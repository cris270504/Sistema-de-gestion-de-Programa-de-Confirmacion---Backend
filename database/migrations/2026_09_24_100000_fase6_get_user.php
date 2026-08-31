<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6 (prep de cutover): las 2 últimas llamadas del frontend a Laravel.
 *
 *   fn_get_user            ← GET /get-user (PassportAuthController::me): hidrata
 *                             el perfil tras login y al arrancar la app.
 *   fn_log_frontend_error  ← POST /logs/frontend-error (FrontendErrorLogController)
 *
 * fn_get_user es SECURITY DEFINER: lee roles/permisos (tablas Spatie REVOCADAS a
 * authenticated) en vivo — así `refrescarUsuario()` detecta cambios de rol sin
 * esperar al refresh del JWT. El id de usuario sale del claim.
 *
 * Con esto el frontend deja de llamar a Laravel por completo.
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
        CREATE OR REPLACE FUNCTION public.fn_get_user()
        RETURNS jsonb
        LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
        DECLARE
            _uid   bigint := public.app_current_user_id();
            _u     record;
            _roles text[];
            _perms text[];
            _prov  boolean;
            _cfg   jsonb;
        BEGIN
            IF _uid IS NULL THEN
                RAISE EXCEPTION 'Sin usuario en el contexto' USING ERRCODE = 'insufficient_privilege';
            END IF;

            SELECT id, name, email, dni, parroquia_id, activo INTO _u
              FROM public.users WHERE id = _uid;
            IF _u.id IS NULL OR NOT _u.activo THEN
                RAISE EXCEPTION 'Usuario no encontrado o inactivo' USING ERRCODE = 'insufficient_privilege';
            END IF;

            SELECT coalesce(array_agg(r.name ORDER BY r.name), '{}') INTO _roles
              FROM public.model_has_roles mhr
              JOIN public.roles r ON r.id = mhr.role_id AND r.guard_name = 'api'
             WHERE mhr.model_type = 'App\Models\User' AND mhr.model_id = _uid;

            _prov := 'proveedor' = ANY(_roles);

            SELECT coalesce(array_agg(DISTINCT name ORDER BY name), '{}') INTO _perms
              FROM (
                SELECT pe.name
                  FROM public.model_has_roles mhr
                  JOIN public.role_has_permissions rhp ON rhp.role_id = mhr.role_id
                  JOIN public.permissions pe ON pe.id = rhp.permission_id AND pe.guard_name = 'api'
                 WHERE mhr.model_type = 'App\Models\User' AND mhr.model_id = _uid
                UNION
                SELECT pe.name
                  FROM public.model_has_permissions mp
                  JOIN public.permissions pe ON pe.id = mp.permission_id AND pe.guard_name = 'api'
                 WHERE mp.model_type = 'App\Models\User' AND mp.model_id = _uid
              ) x;

            SELECT to_jsonb(c) INTO _cfg FROM (
                SELECT programa_inicio, programa_fin, dias_ventana_justificacion,
                       tipos_reunion, umbrales_alerta, procedencias, branding, roles_labels
                  FROM public.parroquia_configuraciones WHERE parroquia_id = _u.parroquia_id
            ) c;

            RETURN jsonb_build_object(
                'id', _u.id, 'name', _u.name, 'email', _u.email, 'dni', _u.dni,
                'roles', to_jsonb(_roles),
                'permissions', to_jsonb(_perms),
                'parroquia', CASE WHEN _prov OR _u.parroquia_id IS NULL THEN NULL ELSE (
                    SELECT jsonb_build_object('id', p.id, 'slug', p.slug, 'nombre', p.nombre)
                      FROM public.parroquias p WHERE p.id = _u.parroquia_id
                ) END,
                'configuracion', coalesce(_cfg, '{}'::jsonb),
                'grupo_ids', coalesce((
                    SELECT jsonb_agg(cg.grupo_id ORDER BY cg.grupo_id)
                      FROM public.catequista_grupo cg WHERE cg.user_id = _uid
                ), '[]'::jsonb),
                'grupos', coalesce((
                    SELECT jsonb_agg(jsonb_build_object('id', g.id, 'nombre', g.nombre) ORDER BY g.id)
                      FROM public.catequista_grupo cg JOIN public.grupos g ON g.id = cg.grupo_id
                     WHERE cg.user_id = _uid
                ), '[]'::jsonb)
            );
        END;
        $$;

        REVOKE ALL ON FUNCTION public.fn_get_user() FROM public;
        GRANT EXECUTE ON FUNCTION public.fn_get_user() TO authenticated;

        -- ── Log de errores del frontend ────────────────────────────────────
        CREATE OR REPLACE FUNCTION public.fn_log_frontend_error(
            p_message    text,
            p_stack      text DEFAULT NULL,
            p_url        text DEFAULT NULL,
            p_user_agent text DEFAULT NULL
        ) RETURNS void
        LANGUAGE plpgsql SECURITY INVOKER SET search_path = public AS $$
        BEGIN
            -- Sin sesión o sin parroquia (proveedor global): no se registra.
            IF public.app_current_user_id() IS NULL OR public.app_current_parroquia_id() IS NULL THEN
                RETURN;
            END IF;
            IF coalesce(btrim(p_message), '') = '' THEN
                RETURN;
            END IF;

            INSERT INTO public.frontend_error_logs
                (user_id, parroquia_id, message, stack, url, user_agent, created_at)
            VALUES (
                public.app_current_user_id(),
                public.app_current_parroquia_id(),
                left(p_message, 2000),
                left(p_stack, 8000),
                left(p_url, 255),
                left(p_user_agent, 255),
                now()
            );
        END;
        $$;

        GRANT INSERT ON public.frontend_error_logs TO authenticated;
        GRANT USAGE, SELECT ON SEQUENCE public.frontend_error_logs_id_seq TO authenticated;
        REVOKE ALL ON FUNCTION public.fn_log_frontend_error(text, text, text, text) FROM public;
        GRANT EXECUTE ON FUNCTION public.fn_log_frontend_error(text, text, text, text) TO authenticated;

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.fn_get_user();
            DROP FUNCTION IF EXISTS public.fn_log_frontend_error(text, text, text, text);
            REVOKE INSERT ON public.frontend_error_logs FROM authenticated;
            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
