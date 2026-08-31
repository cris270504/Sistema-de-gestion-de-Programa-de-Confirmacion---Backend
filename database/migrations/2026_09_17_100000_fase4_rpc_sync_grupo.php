<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 — parte 5: sync de catequistas y confirmandos de un grupo como RPC
 * (plpgsql, SECURITY INVOKER → la RLS de catequista_grupo / confirmandos aplica).
 *
 * - fn_sync_catequistas_grupo  ← GrupoController::syncCatequists
 *   (`$grupo->catequistas()->sync($ids)` sobre el pivote catequista_grupo)
 * - fn_sync_confirmandos_grupo  ← GrupoController::syncConfirmandos
 *   (quita el grupo a los que salen, lo pone a los que entran — no hay pivote,
 *    es la FK confirmandos.grupo_id)
 *
 * Ambas son masivas y deben ser atómicas → RPC en vez de 2 llamadas PostgREST.
 * Gate app_is_privileged() (== permiso "asignar catequista/confirmandos", que
 * solo tienen coordinador/super-admin/proveedor). Devuelven el grupo_id; el
 * frontend re-lee el detalle.
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
        -- Único que el sync() de Eloquent daba por hecho en el pivote.
        CREATE UNIQUE INDEX IF NOT EXISTS catequista_grupo_grupo_id_user_id_uq
            ON public.catequista_grupo (grupo_id, user_id);

        -- ── Catequistas del grupo ──────────────────────────────────────────
        CREATE OR REPLACE FUNCTION public.fn_sync_catequistas_grupo(
            p_grupo_id bigint,
            p_user_ids bigint[]
        ) RETURNS bigint
        LANGUAGE plpgsql
        SECURITY INVOKER
        AS $$
        DECLARE _ids bigint[] := coalesce(p_user_ids, '{}');
        BEGIN
            IF NOT public.app_is_privileged() THEN
                RAISE EXCEPTION 'No autorizado para asignar catequistas'
                    USING ERRCODE = 'insufficient_privilege';
            END IF;
            IF NOT EXISTS (SELECT 1 FROM public.grupos WHERE id = p_grupo_id) THEN
                RAISE EXCEPTION 'Grupo % no encontrado', p_grupo_id
                    USING ERRCODE = 'no_data_found';
            END IF;

            DELETE FROM public.catequista_grupo
             WHERE grupo_id = p_grupo_id
               AND user_id <> ALL(_ids);

            INSERT INTO public.catequista_grupo (grupo_id, user_id, created_at, updated_at)
            SELECT p_grupo_id, u, now(), now()
              FROM unnest(_ids) AS u
            ON CONFLICT (grupo_id, user_id) DO NOTHING;

            RETURN p_grupo_id;
        END;
        $$;

        -- ── Confirmandos del grupo (FK confirmandos.grupo_id, sin pivote) ───
        CREATE OR REPLACE FUNCTION public.fn_sync_confirmandos_grupo(
            p_grupo_id        bigint,
            p_confirmando_ids bigint[]
        ) RETURNS bigint
        LANGUAGE plpgsql
        SECURITY INVOKER
        AS $$
        DECLARE _ids bigint[] := coalesce(p_confirmando_ids, '{}');
        BEGIN
            IF NOT public.app_is_privileged() THEN
                RAISE EXCEPTION 'No autorizado para asignar confirmandos'
                    USING ERRCODE = 'insufficient_privilege';
            END IF;
            IF NOT EXISTS (SELECT 1 FROM public.grupos WHERE id = p_grupo_id) THEN
                RAISE EXCEPTION 'Grupo % no encontrado', p_grupo_id
                    USING ERRCODE = 'no_data_found';
            END IF;

            -- Los que salen del grupo quedan sin grupo.
            UPDATE public.confirmandos
               SET grupo_id = NULL
             WHERE grupo_id = p_grupo_id
               AND id <> ALL(_ids);

            -- Los que entran (la RLS RESTRICTIVE de parroquia filtra ajenos).
            UPDATE public.confirmandos
               SET grupo_id = p_grupo_id
             WHERE id = ANY(_ids);

            RETURN p_grupo_id;
        END;
        $$;

        -- Grants de tabla que faltaban (staging revoca los GRANT ALL por defecto).
        GRANT INSERT, UPDATE, DELETE ON public.catequista_grupo TO authenticated;
        GRANT USAGE, SELECT ON SEQUENCE public.catequista_grupo_id_seq TO authenticated;

        REVOKE ALL ON FUNCTION public.fn_sync_catequistas_grupo(bigint, bigint[]) FROM public;
        REVOKE ALL ON FUNCTION public.fn_sync_confirmandos_grupo(bigint, bigint[]) FROM public;
        GRANT EXECUTE ON FUNCTION public.fn_sync_catequistas_grupo(bigint, bigint[]) TO authenticated;
        GRANT EXECUTE ON FUNCTION public.fn_sync_confirmandos_grupo(bigint, bigint[]) TO authenticated;

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.fn_sync_catequistas_grupo(bigint, bigint[]);
            DROP FUNCTION IF EXISTS public.fn_sync_confirmandos_grupo(bigint, bigint[]);
            DROP INDEX IF EXISTS public.catequista_grupo_grupo_id_user_id_uq;
            REVOKE INSERT, UPDATE, DELETE ON public.catequista_grupo FROM authenticated;
            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
