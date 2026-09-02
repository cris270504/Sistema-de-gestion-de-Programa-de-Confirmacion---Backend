<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `fn_generar_grupos_equitativo` robusto — stress test A1(motor), A4, B2, B3.
 *
 * - A4: advisory lock por (parroquia, periodo) → dos ejecuciones concurrentes ya
 *   no se pisan; la segunda no encuentra confirmandos sin grupo.
 * - A1: el rango de edad sale de `parroquia_configuraciones.grupos_edad_min/max`
 *   (NULL = sin límite), no más 14-18 hardcodeado.
 * - A1: devuelve `no_asignados: [{id, nombres, apellidos, motivo}]` — los que la
 *   UI contaba como "sin grupo" pero el motor excluye (sin género / fuera de
 *   rango de edad). Se acabó la exclusión silenciosa.
 * - B2: valida `p_periodo` (no vacío, <= 20 chars).
 * - B3: los grupos nuevos toman la primera procedencia de la config, no 'sede' fijo.
 *
 * Solo pgsql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION public.fn_generar_grupos_equitativo(
            p_nombres text[],
            p_periodo text,
            p_estrategia text DEFAULT 'genero'
        ) RETURNS jsonb
        LANGUAGE plpgsql
        AS $fn$
        DECLARE
            _parroquia    bigint := public.app_current_parroquia_id();
            _n            int    := array_length(p_nombres, 1);
            _periodo      text   := btrim(coalesce(p_periodo, ''));
            _proc         text;
            _emin         int;
            _emax         int;
            _ins_ids      bigint[];
            _grupo_ids    bigint[];
            _nuevos       int;
            _total        int;
            _asignaciones jsonb;
            _grupos       jsonb;
            _no_asig      jsonb;
        BEGIN
            IF NOT public.app_is_privileged() THEN
                RAISE EXCEPTION 'No autorizado para generar grupos' USING ERRCODE = 'insufficient_privilege';
            END IF;
            IF _n IS NULL OR _n = 0 THEN
                RAISE EXCEPTION 'Debe indicar al menos un nombre de grupo' USING ERRCODE = 'invalid_parameter_value';
            END IF;
            IF p_estrategia NOT IN ('genero', 'edad', 'ninguno') THEN
                RAISE EXCEPTION 'Estrategia de reparto no válida' USING ERRCODE = 'invalid_parameter_value';
            END IF;
            IF _periodo = '' OR length(_periodo) > 20 THEN
                RAISE EXCEPTION 'Indica un periodo válido (ej: 2025-2026)' USING ERRCODE = 'invalid_parameter_value';
            END IF;
            IF (SELECT count(*) <> count(DISTINCT trim(x)) FROM unnest(p_nombres) AS x) THEN
                RAISE EXCEPTION 'Los nombres de grupo no pueden repetirse' USING ERRCODE = 'invalid_parameter_value';
            END IF;

            PERFORM pg_advisory_xact_lock(hashtext('gen_grupos:' || _parroquia || ':' || _periodo));

            SELECT grupos_edad_min, grupos_edad_max, procedencias->>0
              INTO _emin, _emax, _proc
              FROM public.parroquia_configuraciones WHERE parroquia_id = _parroquia;
            _proc := coalesce(_proc, 'sede');

            -- ── 1. firstOrCreate de cada grupo ─────────────────────────────
            WITH input AS (SELECT DISTINCT trim(n) AS nombre FROM unnest(p_nombres) AS t(n)),
            ins AS (
                INSERT INTO public.grupos (nombre, periodo, color, procedencia)
                SELECT i.nombre, _periodo,
                       '#' || lpad(to_hex((random() * 16777215)::int), 6, '0'), _proc
                  FROM input i
                ON CONFLICT (parroquia_id, nombre, periodo) DO NOTHING
                RETURNING id
            )
            SELECT coalesce(array_agg(id), '{}') INTO _ins_ids FROM ins;
            _nuevos := coalesce(array_length(_ins_ids, 1), 0);

            SELECT array_agg(g.id ORDER BY t.ord) INTO _grupo_ids
              FROM unnest(p_nombres) WITH ORDINALITY AS t(n, ord)
              JOIN public.grupos g
                ON g.parroquia_id = _parroquia AND g.nombre = trim(t.n) AND g.periodo = _periodo;

            -- ── 2. Reparto round-robin ────────────────────────────────────
            WITH elegibles AS (
                SELECT id,
                       row_number() OVER (
                           PARTITION BY CASE WHEN p_estrategia = 'genero' THEN lower(genero) END
                           ORDER BY
                               CASE WHEN p_estrategia = 'edad'   THEN fecha_nacimiento END ASC  NULLS LAST,
                               CASE WHEN p_estrategia = 'genero' THEN fecha_nacimiento END DESC NULLS LAST,
                               id
                       ) - 1 AS rn
                  FROM public.confirmandos
                 WHERE grupo_id IS NULL
                   AND estado = 'en_preparacion'
                   AND (p_estrategia <> 'genero' OR lower(genero) IN ('m', 'f'))
                   AND (fecha_nacimiento IS NULL OR (
                        (_emin IS NULL OR extract(year FROM age(current_date, fecha_nacimiento)) >= _emin)
                    AND (_emax IS NULL OR extract(year FROM age(current_date, fecha_nacimiento)) <= _emax)
                   ))
            ),
            asign AS (SELECT id, _grupo_ids[(rn % _n) + 1] AS grupo_id FROM elegibles),
            upd AS (
                UPDATE public.confirmandos c SET grupo_id = a.grupo_id
                  FROM asign a WHERE c.id = a.id
                RETURNING c.id, a.grupo_id
            )
            SELECT count(*)::int, coalesce(jsonb_object_agg(id, grupo_id), '{}'::jsonb)
              INTO _total, _asignaciones FROM upd;

            -- ── 2b. Los que SIGUEN sin grupo tras el reparto + motivo ──────
            SELECT coalesce(jsonb_agg(jsonb_build_object(
                       'id', id, 'nombres', nombres, 'apellidos', apellidos, 'motivo', motivo
                   ) ORDER BY apellidos, nombres), '[]'::jsonb)
              INTO _no_asig
              FROM (
                SELECT id, nombres, apellidos,
                       CASE
                         WHEN p_estrategia = 'genero' AND (genero IS NULL OR lower(genero) NOT IN ('m', 'f'))
                              THEN 'sin género definido (requiere M/F para esta estrategia)'
                         WHEN fecha_nacimiento IS NOT NULL AND _emin IS NOT NULL
                              AND extract(year FROM age(current_date, fecha_nacimiento)) < _emin
                              THEN 'menor de ' || _emin || ' años'
                         WHEN fecha_nacimiento IS NOT NULL AND _emax IS NOT NULL
                              AND extract(year FROM age(current_date, fecha_nacimiento)) > _emax
                              THEN 'mayor de ' || _emax || ' años'
                         ELSE 'no se pudo asignar'
                       END AS motivo
                  FROM public.confirmandos
                 WHERE grupo_id IS NULL AND estado = 'en_preparacion'
              ) x;

            -- ── 3. Grupos del periodo ─────────────────────────────────────
            SELECT coalesce(jsonb_agg(to_jsonb(g) ORDER BY g.id), '[]'::jsonb) INTO _grupos
              FROM (SELECT id, nombre, periodo, color, procedencia
                      FROM public.grupos
                     WHERE parroquia_id = _parroquia AND periodo = _periodo) g;

            RETURN jsonb_build_object(
                'total_asignados',   _total,
                'grupos_nuevos',     _nuevos,
                'grupos_existentes', _n - _nuevos,
                'asignaciones',      _asignaciones,
                'grupos',            _grupos,
                'no_asignados',      _no_asig
            );
        END;
        $fn$;

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared("NOTIFY pgrst, 'reload schema';");
    }
};
