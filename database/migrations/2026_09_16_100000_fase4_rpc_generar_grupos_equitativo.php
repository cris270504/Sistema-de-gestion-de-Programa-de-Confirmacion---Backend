<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 — parte 4: reparto equitativo de confirmandos en grupos como RPC
 * (plpgsql, SECURITY INVOKER → la RLS de grupos/confirmandos aplica).
 *
 * fn_generar_grupos_equitativo ← GrupoDistributionController::generarGruposEquitativos
 *
 * - firstOrCreate de cada nombre por (parroquia_id, nombre, periodo) — se añade el
 *   índice único que el ORM asumía. Grupos nuevos: color aleatorio, procedencia
 *   'sede' por defecto (el modal no la pide; en MySQL el enum tomaba el 1er valor).
 * - Round-robin POR GÉNERO (dos repartos independientes, cada uno arranca en el
 *   grupo 0), sobre confirmandos sin grupo, en preparación, 14–17 años (o sin
 *   fecha). Un solo UPDATE ... FROM con row_number().
 * - Devuelve { total_asignados, grupos_nuevos, grupos_existentes, asignaciones,
 *   grupos } — el mensaje "Caso A/B/C" se arma en el servicio del frontend.
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
        -- Índice único que firstOrCreate(['nombre','periodo']) daba por hecho
        -- (el Global Scope de BelongsToParroquia añade parroquia_id).
        CREATE UNIQUE INDEX IF NOT EXISTS grupos_parroquia_nombre_periodo_uq
            ON public.grupos (parroquia_id, nombre, periodo);

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

            -- ── 1. firstOrCreate de cada grupo ──────────────────────────────
            -- 1a. Alta de los que faltan (los CTE hermanos no verían estas filas,
            --     por eso es su propia sentencia). trg_set_parroquia_id fija
            --     parroquia_id antes del chequeo de unicidad.
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

            -- 1b. Resolver los ids en el ORDEN del array (round-robin usa la posición).
            SELECT array_agg(g.id ORDER BY t.ord)
              INTO _grupo_ids
              FROM unnest(p_nombres) WITH ORDINALITY AS t(n, ord)
              JOIN public.grupos g
                ON g.parroquia_id = _parroquia
               AND g.nombre       = trim(t.n)
               AND g.periodo      = p_periodo;

            -- ── 2. Round-robin por género (M/m y F/f, repartos independientes) ─
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

            -- ── 3. Grupos del periodo (para parchear el estado del front) ─────
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

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.fn_generar_grupos_equitativo(text[], text);
            DROP INDEX IF EXISTS public.grupos_parroquia_nombre_periodo_uq;
            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
