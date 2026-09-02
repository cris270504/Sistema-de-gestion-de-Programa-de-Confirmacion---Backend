<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `fn_roles_lista` deja de mostrar el rol `proveedor` a quien no es proveedor.
 *
 * El rol `proveedor` es único para toda la plataforma (lo tiene el dueño del
 * sistema), no algo que el admin de una parroquia deba ver ni tocar en
 * "Roles y permisos". `fn_guardar_rol` ya impedía editarlo; ahora tampoco
 * aparece en el listado salvo que el que consulta sea el propio proveedor.
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
        CREATE OR REPLACE FUNCTION public.fn_roles_lista()
        RETURNS jsonb
        LANGUAGE plpgsql
        STABLE SECURITY DEFINER
        SET search_path TO 'public'
        AS $fn$
        BEGIN
            IF NOT ('ver roles' = ANY(public.app_current_permisos()) OR public.app_es_proveedor()) THEN
                RAISE EXCEPTION 'No autorizado' USING ERRCODE = 'insufficient_privilege';
            END IF;
            RETURN coalesce((
                SELECT jsonb_agg(jsonb_build_object(
                    'id', r.id, 'name', r.name, 'guard_name', r.guard_name,
                    'permissions', coalesce((
                        SELECT jsonb_agg(jsonb_build_object('id', p.id, 'name', p.name) ORDER BY p.name)
                          FROM public.role_has_permissions rhp
                          JOIN public.permissions p ON p.id = rhp.permission_id
                         WHERE rhp.role_id = r.id
                    ), '[]'::jsonb)
                ) ORDER BY r.id)
                FROM public.roles r
                WHERE r.guard_name = 'api'
                  AND (r.name <> 'proveedor' OR public.app_es_proveedor())
            ), '[]'::jsonb);
        END;
        $fn$;
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION public.fn_roles_lista()
        RETURNS jsonb
        LANGUAGE plpgsql
        STABLE SECURITY DEFINER
        SET search_path TO 'public'
        AS $fn$
        BEGIN
            IF NOT ('ver roles' = ANY(public.app_current_permisos()) OR public.app_es_proveedor()) THEN
                RAISE EXCEPTION 'No autorizado' USING ERRCODE = 'insufficient_privilege';
            END IF;
            RETURN coalesce((
                SELECT jsonb_agg(jsonb_build_object(
                    'id', r.id, 'name', r.name, 'guard_name', r.guard_name,
                    'permissions', coalesce((
                        SELECT jsonb_agg(jsonb_build_object('id', p.id, 'name', p.name) ORDER BY p.name)
                          FROM public.role_has_permissions rhp
                          JOIN public.permissions p ON p.id = rhp.permission_id
                         WHERE rhp.role_id = r.id
                    ), '[]'::jsonb)
                ) ORDER BY r.id)
                FROM public.roles r
                WHERE r.guard_name = 'api'
            ), '[]'::jsonb);
        END;
        $fn$;
        SQL);
    }
};
