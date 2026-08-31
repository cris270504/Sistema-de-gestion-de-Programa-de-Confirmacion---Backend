<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 5 — parte 2: catálogo de roles y permisos (Spatie).
 *
 * Las tablas `roles` / `permissions` / `role_has_permissions` están REVOCADAS a
 * `authenticated` (Fase 2, son infra del SaaS). No tocan `auth.users`, así que
 * NO hace falta Edge Function: bastan funciones SECURITY DEFINER (owner postgres,
 * BYPASSRLS) gateadas por los permisos del claim del JWT (`permisos`), igual que
 * el `permission:` de las rutas de Laravel.
 *
 *   fn_roles_lista / fn_permisos_lista   ← RoleController::index / PermissionController::index
 *   fn_guardar_rol                        ← RoleController::store + update
 *   fn_eliminar_rol                       ← RoleController::destroy
 *
 * Nota: un cambio de permisos de un rol se refleja en un usuario recién en el
 * siguiente refresh de su JWT (los claims no se recalculan en caliente). Era
 * `Cache::forget` en Laravel; acá el hook lee fresco en cada emisión de token.
 *
 * El CRUD de `permissions` (crear/editar/borrar) NO se migra: hoy es código
 * muerto en el frontend (solo se lista para los checkboxes del editor de roles).
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
        -- Permisos del solicitante, desde el claim del JWT.
        CREATE OR REPLACE FUNCTION public.app_current_permisos()
        RETURNS text[] LANGUAGE sql STABLE AS $$
            SELECT coalesce(
                ARRAY(SELECT jsonb_array_elements_text(public.app_jwt() -> 'permisos')),
                '{}'
            )
        $$;

        -- ── Lecturas ───────────────────────────────────────────────────────
        CREATE OR REPLACE FUNCTION public.fn_roles_lista()
        RETURNS jsonb
        LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
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
        $$;

        CREATE OR REPLACE FUNCTION public.fn_permisos_lista()
        RETURNS jsonb
        LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
        BEGIN
            IF NOT ('ver roles' = ANY(public.app_current_permisos()) OR public.app_es_proveedor()) THEN
                RAISE EXCEPTION 'No autorizado' USING ERRCODE = 'insufficient_privilege';
            END IF;
            RETURN coalesce((
                SELECT jsonb_agg(jsonb_build_object('id', p.id, 'name', p.name) ORDER BY p.name)
                  FROM public.permissions p WHERE p.guard_name = 'api'
            ), '[]'::jsonb);
        END;
        $$;

        -- ── Alta / edición de rol ──────────────────────────────────────────
        CREATE OR REPLACE FUNCTION public.fn_guardar_rol(
            p_id          bigint,
            p_name        text,
            p_permissions text[]
        ) RETURNS jsonb
        LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
        DECLARE
            _perms   text[]  := public.app_current_permisos();
            _es_prov boolean := public.app_es_proveedor();
            _sistema text[]  := ARRAY['proveedor', 'super-admin', 'coordinador', 'catequista'];
            _tname   text;
            _rid     bigint  := p_id;
        BEGIN
            IF p_id IS NULL THEN
                IF NOT ('crear roles' = ANY(_perms) OR _es_prov) THEN
                    RAISE EXCEPTION 'No autorizado para crear roles' USING ERRCODE = 'insufficient_privilege';
                END IF;
            ELSE
                IF NOT ('editar roles' = ANY(_perms) OR _es_prov) THEN
                    RAISE EXCEPTION 'No autorizado para editar roles' USING ERRCODE = 'insufficient_privilege';
                END IF;
                SELECT name INTO _tname FROM public.roles WHERE id = p_id AND guard_name = 'api';
                IF _tname IS NULL THEN
                    RAISE EXCEPTION 'Rol % no encontrado', p_id USING ERRCODE = 'no_data_found';
                END IF;
                IF (NOT _es_prov) AND _tname = ANY(_sistema) THEN
                    RAISE EXCEPTION 'Los roles del sistema solo los administra el proveedor de la plataforma.'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
            END IF;

            -- Nombre: obligatorio en alta, nunca un nombre reservado (== Rule::notIn).
            IF p_name IS NOT NULL THEN
                IF btrim(p_name) = '' THEN
                    RAISE EXCEPTION 'El nombre del rol es obligatorio' USING ERRCODE = 'check_violation';
                END IF;
                IF btrim(p_name) = ANY(_sistema) THEN
                    RAISE EXCEPTION 'Ese nombre está reservado para un rol del sistema' USING ERRCODE = 'check_violation';
                END IF;
            ELSIF p_id IS NULL THEN
                RAISE EXCEPTION 'El nombre del rol es obligatorio' USING ERRCODE = 'check_violation';
            END IF;

            -- Permisos: solo los que el actor posee (salvo proveedor) y que existan.
            IF p_permissions IS NOT NULL THEN
                IF (NOT _es_prov) AND EXISTS (SELECT 1 FROM unnest(p_permissions) x WHERE x <> ALL(_perms)) THEN
                    RAISE EXCEPTION 'No puedes asignar un permiso que no tienes' USING ERRCODE = 'insufficient_privilege';
                END IF;
                IF EXISTS (SELECT 1 FROM unnest(p_permissions) x
                            WHERE x NOT IN (SELECT name FROM public.permissions WHERE guard_name = 'api')) THEN
                    RAISE EXCEPTION 'Permiso no válido' USING ERRCODE = 'check_violation';
                END IF;
            END IF;

            IF p_id IS NULL THEN
                INSERT INTO public.roles (name, guard_name, created_at, updated_at)
                VALUES (btrim(p_name), 'api', now(), now())
                RETURNING id INTO _rid;
            ELSIF p_name IS NOT NULL THEN
                UPDATE public.roles SET name = btrim(p_name), updated_at = now() WHERE id = _rid;
            END IF;

            IF p_permissions IS NOT NULL THEN
                DELETE FROM public.role_has_permissions WHERE role_id = _rid;
                INSERT INTO public.role_has_permissions (permission_id, role_id)
                SELECT p.id, _rid FROM public.permissions p
                 WHERE p.guard_name = 'api' AND p.name = ANY(p_permissions);
            END IF;

            RETURN (
                SELECT jsonb_build_object(
                    'id', r.id, 'name', r.name, 'guard_name', r.guard_name,
                    'permissions', coalesce((
                        SELECT jsonb_agg(jsonb_build_object('id', p.id, 'name', p.name) ORDER BY p.name)
                          FROM public.role_has_permissions rhp
                          JOIN public.permissions p ON p.id = rhp.permission_id
                         WHERE rhp.role_id = r.id
                    ), '[]'::jsonb)
                )
                FROM public.roles r WHERE r.id = _rid
            );
        END;
        $$;

        -- ── Eliminar rol ───────────────────────────────────────────────────
        CREATE OR REPLACE FUNCTION public.fn_eliminar_rol(p_id bigint)
        RETURNS jsonb
        LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
        DECLARE
            _perms   text[]  := public.app_current_permisos();
            _es_prov boolean := public.app_es_proveedor();
            _sistema text[]  := ARRAY['proveedor', 'super-admin', 'coordinador', 'catequista'];
            _tname   text;
        BEGIN
            IF NOT ('eliminar roles' = ANY(_perms) OR _es_prov) THEN
                RAISE EXCEPTION 'No autorizado para eliminar roles' USING ERRCODE = 'insufficient_privilege';
            END IF;
            SELECT name INTO _tname FROM public.roles WHERE id = p_id AND guard_name = 'api';
            IF _tname IS NULL THEN
                RAISE EXCEPTION 'Rol % no encontrado', p_id USING ERRCODE = 'no_data_found';
            END IF;
            IF _tname = ANY(_sistema) THEN
                RAISE EXCEPTION 'No puedes eliminar roles del sistema' USING ERRCODE = 'insufficient_privilege';
            END IF;

            DELETE FROM public.roles WHERE id = p_id;  -- role_has_permissions / model_has_roles: FK CASCADE
            RETURN jsonb_build_object('id', p_id);
        END;
        $$;

        DO $$
        DECLARE _fn text;
        BEGIN
            FOREACH _fn IN ARRAY ARRAY[
                'fn_roles_lista()', 'fn_permisos_lista()',
                'fn_guardar_rol(bigint, text, text[])', 'fn_eliminar_rol(bigint)'
            ] LOOP
                EXECUTE format('REVOKE ALL ON FUNCTION public.%s FROM public', _fn);
                EXECUTE format('GRANT EXECUTE ON FUNCTION public.%s TO authenticated', _fn);
            END LOOP;
        END $$;

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.fn_roles_lista();
            DROP FUNCTION IF EXISTS public.fn_permisos_lista();
            DROP FUNCTION IF EXISTS public.fn_guardar_rol(bigint, text, text[]);
            DROP FUNCTION IF EXISTS public.fn_eliminar_rol(bigint);
            DROP FUNCTION IF EXISTS public.app_current_permisos();
            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
