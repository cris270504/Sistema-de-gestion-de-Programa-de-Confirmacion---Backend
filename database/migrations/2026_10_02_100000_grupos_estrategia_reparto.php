<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Regla de negocio configurable #2: estrategia del reparto equitativo de grupos.
 *
 * fn_generar_grupos_equitativo gana un parámetro `p_estrategia`:
 *   - 'genero'  (default, comportamiento actual): dos repartos round-robin
 *     independientes (M y F) → cada grupo queda balanceado por sexo. Excluye a
 *     los confirmandos sin género (como hoy).
 *   - 'edad':   un solo reparto, ordenado por fecha de nacimiento → cada grupo
 *     queda con un rango de edades parecido. Incluye a todos.
 *   - 'ninguno': round-robin por orden de id, sin criterio. Solo empareja
 *     cantidades. Incluye a todos.
 *
 * Es una decisión por corrida (dropdown en el modal), no config persistente.
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
        DROP FUNCTION IF EXISTS public.fn_generar_grupos_equitativo(text[], text);

        CREATE OR REPLACE FUNCTION public.fn_generar_grupos_equitativo(
            p_nombres   text[],
            p_periodo   text,
            p_estrategia text DEFAULT 'genero'
        ) RETURNS jsonb
        LANGUAGE plpgsql
        SECURITY INVOKER
        AS $$
        DECLARE
            _parroquia    bigint := public.app_current_parroquia_id();
            _n            int    := array_length(p_nombres, 1);
            _ins_ids      bigint[];
            _grupo_ids    bigint[];
            _nuevos       int;
            _total        int;
            _asignaciones jsonb;
            _grupos       jsonb;
            _edad_max     date := (now() - interval '14 years')::date;
            _edad_min     date := (now() - interval '18 years')::date + 1;
        BEGIN
            IF NOT public.app_is_privileged() THEN
                RAISE EXCEPTION 'No autorizado para generar grupos'
                    USING ERRCODE = 'insufficient_privilege';
            END IF;
            IF _n IS NULL OR _n = 0 THEN
                RAISE EXCEPTION 'Debe indicar al menos un nombre de grupo'
                    USING ERRCODE = 'invalid_parameter_value';
            END IF;
            IF p_estrategia NOT IN ('genero', 'edad', 'ninguno') THEN
                RAISE EXCEPTION 'Estrategia de reparto no válida' USING ERRCODE = 'invalid_parameter_value';
            END IF;
            IF (SELECT count(*) <> count(DISTINCT trim(x))
                  FROM unnest(p_nombres) AS x) THEN
                RAISE EXCEPTION 'Los nombres de grupo no pueden repetirse'
                    USING ERRCODE = 'invalid_parameter_value';
            END IF;

            -- ── 1. firstOrCreate de cada grupo ──────────────────────────────
            WITH input AS (
                SELECT DISTINCT trim(n) AS nombre FROM unnest(p_nombres) AS t(n)
            ),
            ins AS (
                INSERT INTO public.grupos (nombre, periodo, color, procedencia)
                SELECT i.nombre, p_periodo,
                       '#' || lpad(to_hex((random() * 16777215)::int), 6, '0'),
                       'sede'
                  FROM input i
                ON CONFLICT (parroquia_id, nombre, periodo) DO NOTHING
                RETURNING id
            )
            SELECT coalesce(array_agg(id), '{}') INTO _ins_ids FROM ins;

            _nuevos := coalesce(array_length(_ins_ids, 1), 0);

            SELECT array_agg(g.id ORDER BY t.ord)
              INTO _grupo_ids
              FROM unnest(p_nombres) WITH ORDINALITY AS t(n, ord)
              JOIN public.grupos g
                ON g.parroquia_id = _parroquia
               AND g.nombre       = trim(t.n)
               AND g.periodo      = p_periodo;

            -- ── 2. Reparto round-robin según la estrategia ──────────────────
            WITH elegibles AS (
                SELECT id,
                       row_number() OVER (
                           PARTITION BY CASE WHEN p_estrategia = 'genero' THEN lower(genero) END
                           ORDER BY
                               CASE WHEN p_estrategia = 'edad'
                                    THEN fecha_nacimiento END ASC NULLS LAST,
                               CASE WHEN p_estrategia = 'genero'
                                    THEN fecha_nacimiento END DESC NULLS LAST,
                               id
                       ) - 1 AS rn
                  FROM public.confirmandos
                 WHERE grupo_id IS NULL
                   AND estado = 'en_preparacion'
                   AND (p_estrategia <> 'genero' OR lower(genero) IN ('m', 'f'))
                   AND (fecha_nacimiento IS NULL
                        OR fecha_nacimiento BETWEEN _edad_min AND _edad_max)
            ),
            asign AS (
                SELECT id, _grupo_ids[(rn % _n) + 1] AS grupo_id FROM elegibles
            ),
            upd AS (
                UPDATE public.confirmandos c
                   SET grupo_id = a.grupo_id
                  FROM asign a
                 WHERE c.id = a.id
                RETURNING c.id, a.grupo_id
            )
            SELECT count(*)::int,
                   coalesce(jsonb_object_agg(id, grupo_id), '{}'::jsonb)
              INTO _total, _asignaciones
              FROM upd;

            -- ── 3. Grupos del periodo ──────────────────────────────────────
            SELECT coalesce(jsonb_agg(to_jsonb(g) ORDER BY g.id), '[]'::jsonb)
              INTO _grupos
              FROM (
                SELECT id, nombre, periodo, color, procedencia
                  FROM public.grupos
                 WHERE parroquia_id = _parroquia AND periodo = p_periodo
              ) g;

            RETURN jsonb_build_object(
                'total_asignados',   _total,
                'grupos_nuevos',     _nuevos,
                'grupos_existentes', _n - _nuevos,
                'asignaciones',      _asignaciones,
                'grupos',            _grupos
            );
        END;
        $$;

        REVOKE ALL ON FUNCTION public.fn_generar_grupos_equitativo(text[], text, text) FROM public;
        GRANT EXECUTE ON FUNCTION public.fn_generar_grupos_equitativo(text[], text, text) TO authenticated;

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
        DROP FUNCTION IF EXISTS public.fn_generar_grupos_equitativo(text[], text, text);

        CREATE OR REPLACE FUNCTION public.fn_generar_grupos_equitativo(
            p_nombres text[],
            p_periodo text
        ) RETURNS jsonb
        LANGUAGE plpgsql
        SECURITY INVOKER
        AS $$
        DECLARE
            _parroquia    bigint := public.app_current_parroquia_id();
            _n            int    := array_length(p_nombres, 1);
            _ins_ids      bigint[];
            _grupo_ids    bigint[];
            _nuevos       int;
            _total        int;
            _asignaciones jsonb;
            _grupos       jsonb;
            _edad_max     date := (now() - interval '14 years')::date;
            _edad_min     date := (now() - interval '18 years')::date + 1;
        BEGIN
            IF NOT public.app_is_privileged() THEN
                RAISE EXCEPTION 'No autorizado para generar grupos'
                    USING ERRCODE = 'insufficient_privilege';
            END IF;
            IF _n IS NULL OR _n = 0 THEN
                RAISE EXCEPTION 'Debe indicar al menos un nombre de grupo'
                    USING ERRCODE = 'invalid_parameter_value';
            END IF;
            IF (SELECT count(*) <> count(DISTINCT trim(x))
                  FROM unnest(p_nombres) AS x) THEN
                RAISE EXCEPTION 'Los nombres de grupo no pueden repetirse'
                    USING ERRCODE = 'invalid_parameter_value';
            END IF;

            WITH input AS (
                SELECT DISTINCT trim(n) AS nombre FROM unnest(p_nombres) AS t(n)
            ),
            ins AS (
                INSERT INTO public.grupos (nombre, periodo, color, procedencia)
                SELECT i.nombre, p_periodo,
                       '#' || lpad(to_hex((random() * 16777215)::int), 6, '0'),
                       'sede'
                  FROM input i
                ON CONFLICT (parroquia_id, nombre, periodo) DO NOTHING
                RETURNING id
            )
            SELECT coalesce(array_agg(id), '{}') INTO _ins_ids FROM ins;

            _nuevos := coalesce(array_length(_ins_ids, 1), 0);

            SELECT array_agg(g.id ORDER BY t.ord)
              INTO _grupo_ids
              FROM unnest(p_nombres) WITH ORDINALITY AS t(n, ord)
              JOIN public.grupos g
                ON g.parroquia_id = _parroquia
               AND g.nombre       = trim(t.n)
               AND g.periodo      = p_periodo;

            WITH elegibles AS (
                SELECT id,
                       row_number() OVER (
                           PARTITION BY lower(genero)
                           ORDER BY fecha_nacimiento DESC NULLS LAST, id
                       ) - 1 AS rn
                  FROM public.confirmandos
                 WHERE grupo_id IS NULL
                   AND estado = 'en_preparacion'
                   AND lower(genero) IN ('m', 'f')
                   AND (fecha_nacimiento IS NULL
                        OR fecha_nacimiento BETWEEN _edad_min AND _edad_max)
            ),
            asign AS (
                SELECT id, _grupo_ids[(rn % _n) + 1] AS grupo_id FROM elegibles
            ),
            upd AS (
                UPDATE public.confirmandos c
                   SET grupo_id = a.grupo_id
                  FROM asign a
                 WHERE c.id = a.id
                RETURNING c.id, a.grupo_id
            )
            SELECT count(*)::int,
                   coalesce(jsonb_object_agg(id, grupo_id), '{}'::jsonb)
              INTO _total, _asignaciones
              FROM upd;

            SELECT coalesce(jsonb_agg(to_jsonb(g) ORDER BY g.id), '[]'::jsonb)
              INTO _grupos
              FROM (
                SELECT id, nombre, periodo, color, procedencia
                  FROM public.grupos
                 WHERE parroquia_id = _parroquia AND periodo = p_periodo
              ) g;

            RETURN jsonb_build_object(
                'total_asignados',   _total,
                'grupos_nuevos',     _nuevos,
                'grupos_existentes', _n - _nuevos,
                'asignaciones',      _asignaciones,
                'grupos',            _grupos
            );
        END;
        $$;

        REVOKE ALL ON FUNCTION public.fn_generar_grupos_equitativo(text[], text) FROM public;
        GRANT EXECUTE ON FUNCTION public.fn_generar_grupos_equitativo(text[], text) TO authenticated;

        NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
