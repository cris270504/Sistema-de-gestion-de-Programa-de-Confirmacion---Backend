<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 5 — parte 1: gestión de usuarios.
 *
 * Las escrituras crean/actualizan/borran filas en `auth.users`, así que la
 * orquestación vive en la Edge Function `admin-usuarios` (Auth Admin API). La
 * parte transaccional de BD (public.users + model_has_roles + catequista_grupo +
 * limpieza de historial al borrar) se hace en estas funciones SECURITY DEFINER,
 * que la Edge Function llama con el rol `service_role` pasándole el `auth.uid()`
 * del que hace la petición (ya verificado por la función).
 *
 *   fn_admin_guardar_usuario   ← UserController::store + update
 *   fn_admin_estado_usuario    ← UserController::estado
 *   fn_admin_eliminar_usuario  ← UserController::destroy (devuelve el auth_id
 *                                 para que la Edge Function borre auth.users)
 *
 * La LECTURA (index / show) pasa a la vista `v_usuarios` (PostgREST): los roles y
 * grupos se resuelven con helpers SECURITY DEFINER (tablas Spatie REVOCADAS a
 * authenticated en Fase 2); la RLS de `users` (privilegiado ve su parroquia)
 * sigue aplicando porque la vista es security_invoker.
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
        -- ── Helpers de lectura (roles + grupos de un usuario) ───────────────
        CREATE OR REPLACE FUNCTION public.app_user_roles_detalle(p_user_id bigint)
        RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
            SELECT coalesce(jsonb_agg(jsonb_build_object('id', r.id, 'name', r.name) ORDER BY r.id), '[]'::jsonb)
              FROM public.model_has_roles mhr
              JOIN public.roles r ON r.id = mhr.role_id AND r.guard_name = 'api'
             WHERE mhr.model_type = 'App\Models\User' AND mhr.model_id = p_user_id
        $$;

        CREATE OR REPLACE FUNCTION public.app_user_grupos_detalle(p_user_id bigint)
        RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
            SELECT coalesce(jsonb_agg(
                       jsonb_build_object('id', g.id, 'nombre', g.nombre,
                                          'color', g.color, 'procedencia', g.procedencia)
                       ORDER BY g.id), '[]'::jsonb)
              FROM public.catequista_grupo cg
              JOIN public.grupos g ON g.id = cg.grupo_id
             WHERE cg.user_id = p_user_id
        $$;

        REVOKE ALL ON FUNCTION public.app_user_roles_detalle(bigint) FROM public;
        REVOKE ALL ON FUNCTION public.app_user_grupos_detalle(bigint) FROM public;
        GRANT EXECUTE ON FUNCTION public.app_user_roles_detalle(bigint) TO authenticated;
        GRANT EXECUTE ON FUNCTION public.app_user_grupos_detalle(bigint) TO authenticated;

        CREATE OR REPLACE VIEW public.v_usuarios WITH (security_invoker = on) AS
        SELECT u.id, u.name, u.email, u.dni,
               nullif(rtrim(u.celular), '') AS celular,   -- celular es char(255): sin padding
               u.fecha_nacimiento,
               u.activo, u.grupo_id, u.parroquia_id, u.created_at,
               public.app_user_roles_detalle(u.id)  AS roles,
               public.app_user_grupos_detalle(u.id) AS grupos
          FROM public.users u;

        GRANT SELECT ON public.v_usuarios TO authenticated;

        -- ── Contexto del actor (para las 3 funciones de escritura) ──────────
        CREATE OR REPLACE FUNCTION public.app_actor_ctx(p_actor_auth uuid)
        RETURNS TABLE (id bigint, parroquia_id bigint, privilegiado boolean, proveedor boolean)
        LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
            SELECT u.id, u.parroquia_id,
                   EXISTS (SELECT 1 FROM public.model_has_roles m JOIN public.roles r ON r.id = m.role_id
                            WHERE m.model_type = 'App\Models\User' AND m.model_id = u.id
                              AND r.name IN ('coordinador', 'super-admin', 'proveedor')),
                   EXISTS (SELECT 1 FROM public.model_has_roles m JOIN public.roles r ON r.id = m.role_id
                            WHERE m.model_type = 'App\Models\User' AND m.model_id = u.id AND r.name = 'proveedor')
              FROM public.users u
             WHERE u.auth_id = p_actor_auth
               AND u.activo
        $$;

        -- ── Datos de auth del objetivo (para que la Edge Function sincronice
        --    email/password/ban en auth.users). Misma autz que las demás. ─────
        CREATE OR REPLACE FUNCTION public.fn_admin_target_auth(
            p_actor_auth uuid, p_id bigint
        ) RETURNS jsonb
        LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
        DECLARE _act record; _tpar bigint; _aid uuid; _email text;
        BEGIN
            SELECT * INTO _act FROM public.app_actor_ctx(p_actor_auth);
            IF _act.id IS NULL OR NOT _act.privilegiado THEN
                RAISE EXCEPTION 'No autorizado' USING ERRCODE = 'insufficient_privilege';
            END IF;
            SELECT parroquia_id, auth_id, email INTO _tpar, _aid, _email
              FROM public.users WHERE id = p_id;
            IF _tpar IS NULL OR (_tpar <> _act.parroquia_id AND NOT _act.proveedor) THEN
                RAISE EXCEPTION 'Usuario % no encontrado', p_id USING ERRCODE = 'no_data_found';
            END IF;
            RETURN jsonb_build_object('auth_id', _aid, 'email', _email);
        END;
        $$;

        -- ── Alta / edición ─────────────────────────────────────────────────
        CREATE OR REPLACE FUNCTION public.fn_admin_guardar_usuario(
            p_actor_auth    uuid,
            p_id            bigint,
            p_new_auth_id   uuid,
            p_datos         jsonb,
            p_roles         text[],
            p_grupo_ids     bigint[],
            p_temp_password text
        ) RETURNS jsonb
        LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
        DECLARE
            _act    record;
            _tpar   bigint;
        BEGIN
            SELECT * INTO _act FROM public.app_actor_ctx(p_actor_auth);
            IF _act.id IS NULL OR NOT _act.privilegiado THEN
                RAISE EXCEPTION 'No autorizado para gestionar usuarios' USING ERRCODE = 'insufficient_privilege';
            END IF;

            IF p_roles IS NOT NULL THEN
                IF coalesce(array_length(p_roles, 1), 0) < 1 THEN
                    RAISE EXCEPTION 'Selecciona al menos un rol' USING ERRCODE = 'check_violation';
                END IF;
                IF (NOT _act.proveedor) AND ('proveedor' = ANY(p_roles)) THEN
                    RAISE EXCEPTION 'No puedes asignar el rol proveedor' USING ERRCODE = 'insufficient_privilege';
                END IF;
                IF EXISTS (SELECT 1 FROM unnest(p_roles) x
                            WHERE x NOT IN (SELECT name FROM public.roles WHERE guard_name = 'api')) THEN
                    RAISE EXCEPTION 'Rol no válido' USING ERRCODE = 'check_violation';
                END IF;
            END IF;

            IF p_grupo_ids IS NOT NULL AND coalesce(array_length(p_grupo_ids, 1), 0) > 0 THEN
                IF (SELECT count(DISTINCT x) FROM unnest(p_grupo_ids) x) <>
                   (SELECT count(*) FROM public.grupos WHERE id = ANY(p_grupo_ids) AND parroquia_id = _act.parroquia_id) THEN
                    RAISE EXCEPTION 'Grupo no válido para esta parroquia' USING ERRCODE = 'check_violation';
                END IF;
            END IF;

            IF p_id IS NULL THEN
                INSERT INTO public.users
                    (name, dni, celular, email, fecha_nacimiento, password,
                     parroquia_id, auth_id, activo, created_at, updated_at)
                VALUES (
                    p_datos->>'name',
                    nullif(p_datos->>'dni', ''),
                    nullif(p_datos->>'celular', ''),
                    lower(p_datos->>'email'),
                    nullif(p_datos->>'fecha_nacimiento', '')::date,
                    extensions.crypt(p_temp_password, extensions.gen_salt('bf')),
                    _act.parroquia_id, p_new_auth_id, true, now(), now()
                )
                RETURNING id INTO p_id;
            ELSE
                SELECT parroquia_id INTO _tpar FROM public.users WHERE id = p_id;
                IF _tpar IS NULL OR (_tpar <> _act.parroquia_id AND NOT _act.proveedor) THEN
                    RAISE EXCEPTION 'Usuario % no encontrado', p_id USING ERRCODE = 'no_data_found';
                END IF;
                UPDATE public.users SET
                    name             = CASE WHEN jsonb_exists(p_datos, 'name') THEN p_datos->>'name' ELSE name END,
                    dni              = CASE WHEN jsonb_exists(p_datos, 'dni') THEN nullif(p_datos->>'dni', '') ELSE dni END,
                    celular          = CASE WHEN jsonb_exists(p_datos, 'celular') THEN nullif(p_datos->>'celular', '') ELSE celular END,
                    email            = CASE WHEN jsonb_exists(p_datos, 'email') THEN lower(p_datos->>'email') ELSE email END,
                    fecha_nacimiento = CASE WHEN jsonb_exists(p_datos, 'fecha_nacimiento') THEN nullif(p_datos->>'fecha_nacimiento', '')::date ELSE fecha_nacimiento END,
                    updated_at       = now()
                WHERE id = p_id;
            END IF;

            IF p_roles IS NOT NULL THEN
                DELETE FROM public.model_has_roles WHERE model_type = 'App\Models\User' AND model_id = p_id;
                INSERT INTO public.model_has_roles (role_id, model_type, model_id)
                SELECT r.id, 'App\Models\User', p_id
                  FROM public.roles r WHERE r.guard_name = 'api' AND r.name = ANY(p_roles);
            END IF;

            IF p_grupo_ids IS NOT NULL THEN
                DELETE FROM public.catequista_grupo
                 WHERE user_id = p_id AND grupo_id <> ALL(coalesce(p_grupo_ids, '{}'));
                INSERT INTO public.catequista_grupo (user_id, grupo_id, created_at, updated_at)
                SELECT p_id, g, now(), now() FROM unnest(coalesce(p_grupo_ids, '{}')) g
                ON CONFLICT (grupo_id, user_id) DO NOTHING;
            END IF;

            RETURN (SELECT to_jsonb(v) FROM public.v_usuarios v WHERE v.id = p_id);
        END;
        $$;

        -- ── Activar / desactivar ───────────────────────────────────────────
        CREATE OR REPLACE FUNCTION public.fn_admin_estado_usuario(
            p_actor_auth uuid, p_id bigint, p_activo boolean
        ) RETURNS jsonb
        LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
        DECLARE _act record; _tpar bigint;
        BEGIN
            SELECT * INTO _act FROM public.app_actor_ctx(p_actor_auth);
            IF _act.id IS NULL OR NOT _act.privilegiado THEN
                RAISE EXCEPTION 'No autorizado' USING ERRCODE = 'insufficient_privilege';
            END IF;
            IF _act.id = p_id THEN
                RAISE EXCEPTION 'No puedes cambiar el estado de tu propia cuenta' USING ERRCODE = 'check_violation';
            END IF;
            SELECT parroquia_id INTO _tpar FROM public.users WHERE id = p_id;
            IF _tpar IS NULL OR (_tpar <> _act.parroquia_id AND NOT _act.proveedor) THEN
                RAISE EXCEPTION 'Usuario % no encontrado', p_id USING ERRCODE = 'no_data_found';
            END IF;

            UPDATE public.users SET activo = p_activo, updated_at = now() WHERE id = p_id;
            RETURN jsonb_build_object('id', p_id, 'activo', p_activo);
        END;
        $$;

        -- ── Eliminar (limpieza + devuelve auth_id) ─────────────────────────
        CREATE OR REPLACE FUNCTION public.fn_admin_eliminar_usuario(
            p_actor_auth uuid, p_id bigint
        ) RETURNS jsonb
        LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
        DECLARE _act record; _tpar bigint; _aid uuid;
        BEGIN
            SELECT * INTO _act FROM public.app_actor_ctx(p_actor_auth);
            IF _act.id IS NULL OR NOT _act.privilegiado THEN
                RAISE EXCEPTION 'No autorizado' USING ERRCODE = 'insufficient_privilege';
            END IF;
            IF _act.id = p_id THEN
                RAISE EXCEPTION 'No puedes eliminar tu propia cuenta' USING ERRCODE = 'check_violation';
            END IF;
            SELECT parroquia_id, auth_id INTO _tpar, _aid FROM public.users WHERE id = p_id;
            IF _tpar IS NULL OR (_tpar <> _act.parroquia_id AND NOT _act.proveedor) THEN
                RAISE EXCEPTION 'Usuario % no encontrado', p_id USING ERRCODE = 'no_data_found';
            END IF;
            IF EXISTS (SELECT 1 FROM public.catequista_grupo WHERE user_id = p_id) THEN
                RAISE EXCEPTION 'Este usuario tiene grupos asignados. Reasígnalos antes de eliminarlo, o desactívalo.'
                    USING ERRCODE = 'check_violation';
            END IF;

            DELETE FROM public.justificaciones
             WHERE asistencia_id IN (SELECT id FROM public.asistencia
                                      WHERE asistente_type = 'App\Models\User' AND asistente_id = p_id);
            DELETE FROM public.asistencia WHERE asistente_type = 'App\Models\User' AND asistente_id = p_id;
            DELETE FROM public.model_has_roles       WHERE model_type = 'App\Models\User' AND model_id = p_id;
            DELETE FROM public.model_has_permissions WHERE model_type = 'App\Models\User' AND model_id = p_id;
            -- reunion_user se borra por FK ON DELETE CASCADE
            DELETE FROM public.users WHERE id = p_id;

            RETURN jsonb_build_object('auth_id', _aid);
        END;
        $$;

        -- Solo service_role: la Edge Function verifica el JWT del llamador y le
        -- pasa su auth.uid() real; authenticated NUNCA las llama directo.
        DO $$
        DECLARE _fn text;
        BEGIN
            FOREACH _fn IN ARRAY ARRAY[
                'app_actor_ctx(uuid)',
                'fn_admin_target_auth(uuid, bigint)',
                'fn_admin_guardar_usuario(uuid, bigint, uuid, jsonb, text[], bigint[], text)',
                'fn_admin_estado_usuario(uuid, bigint, boolean)',
                'fn_admin_eliminar_usuario(uuid, bigint)'
            ] LOOP
                EXECUTE format('REVOKE ALL ON FUNCTION public.%s FROM public, anon, authenticated', _fn);
                EXECUTE format('GRANT EXECUTE ON FUNCTION public.%s TO service_role', _fn);
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
            DROP VIEW IF EXISTS public.v_usuarios;
            DROP FUNCTION IF EXISTS public.fn_admin_guardar_usuario(uuid, bigint, uuid, jsonb, text[], bigint[], text);
            DROP FUNCTION IF EXISTS public.fn_admin_estado_usuario(uuid, bigint, boolean);
            DROP FUNCTION IF EXISTS public.fn_admin_eliminar_usuario(uuid, bigint);
            DROP FUNCTION IF EXISTS public.fn_admin_target_auth(uuid, bigint);
            DROP FUNCTION IF EXISTS public.app_actor_ctx(uuid);
            DROP FUNCTION IF EXISTS public.app_user_roles_detalle(bigint);
            DROP FUNCTION IF EXISTS public.app_user_grupos_detalle(bigint);
            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
