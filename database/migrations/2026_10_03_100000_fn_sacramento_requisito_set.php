<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Toggle de una celda de la matriz requisito × sacramento.
 *
 * Antes la sincronización del pivote `sacramento_requisito` la hacía
 * SacramentoController::sync() en Laravel; al apagar Render, el front intentaba
 * mandar `requisitos: [...]` como columna a PostgREST (columna inexistente).
 * Esta RPC lo reemplaza a nivel de celda, que es lo que necesita la vista de
 * matriz.
 *
 * SECURITY DEFINER + gate app_is_privileged() + ambos ids deben ser de la
 * parroquia del actor (no se puede tocar el catálogo de otra).
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
        CREATE OR REPLACE FUNCTION public.fn_sacramento_requisito_set(
            p_sacramento_id bigint,
            p_requisito_id  bigint,
            p_activo        boolean
        ) RETURNS void
        LANGUAGE plpgsql
        SECURITY DEFINER
        SET search_path = public
        AS $$
        DECLARE _pid bigint := public.app_current_parroquia_id();
        BEGIN
            IF NOT public.app_is_privileged() THEN
                RAISE EXCEPTION 'No autorizado para editar el catálogo sacramental'
                    USING ERRCODE = 'insufficient_privilege';
            END IF;
            IF _pid IS NULL THEN
                RAISE EXCEPTION 'Sin parroquia en el contexto' USING ERRCODE = 'invalid_parameter_value';
            END IF;
            IF NOT EXISTS (SELECT 1 FROM public.sacramentos WHERE id = p_sacramento_id AND parroquia_id = _pid)
               OR NOT EXISTS (SELECT 1 FROM public.requisitos WHERE id = p_requisito_id AND parroquia_id = _pid) THEN
                RAISE EXCEPTION 'Sacramento o requisito no encontrado' USING ERRCODE = 'no_data_found';
            END IF;

            IF p_activo THEN
                INSERT INTO public.sacramento_requisito (sacramento_id, requisito_id, created_at, updated_at)
                VALUES (p_sacramento_id, p_requisito_id, now(), now())
                ON CONFLICT (sacramento_id, requisito_id) DO NOTHING;
            ELSE
                DELETE FROM public.sacramento_requisito
                 WHERE sacramento_id = p_sacramento_id AND requisito_id = p_requisito_id;
            END IF;
        END;
        $$;

        REVOKE ALL ON FUNCTION public.fn_sacramento_requisito_set(bigint, bigint, boolean) FROM public;
        GRANT EXECUTE ON FUNCTION public.fn_sacramento_requisito_set(bigint, bigint, boolean) TO authenticated;

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.fn_sacramento_requisito_set(bigint, bigint, boolean);
            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
