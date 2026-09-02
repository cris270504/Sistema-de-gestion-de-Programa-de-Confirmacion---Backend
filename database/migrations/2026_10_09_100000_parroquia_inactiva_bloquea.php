<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Una parroquia desactivada (`parroquias.activa = false`) vuelve a bloquear a sus
 * usuarios — se había perdido al quitar el middleware `ParroquiaActiva` de Laravel
 * en el cutover a Supabase.
 *
 *   app_parroquia_activa()  → true si la parroquia del claim está activa (o el
 *                             que consulta es proveedor, o no hay parroquia).
 *   app_is_privileged()     → ahora exige además app_parroquia_activa(): bloquea
 *                             en el acto toda escritura privilegiada (RLS `_write`
 *                             + RPCs con gate de privilegio) de una parroquia
 *                             inactiva.
 *   fn_get_user()           → lanza si la parroquia está inactiva → el login falla
 *                             y las sesiones vivas se cierran al recargar la app.
 *
 * El proveedor nunca se bloquea.
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
        CREATE OR REPLACE FUNCTION public.app_parroquia_activa()
        RETURNS boolean
        LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
        AS $fn$
            SELECT public.app_es_proveedor()
                OR coalesce(
                    (SELECT p.activa FROM public.parroquias p
                      WHERE p.id = public.app_current_parroquia_id()),
                    true
                )
        $fn$;
        REVOKE ALL ON FUNCTION public.app_parroquia_activa() FROM public;
        GRANT EXECUTE ON FUNCTION public.app_parroquia_activa() TO anon, authenticated, service_role;

        CREATE OR REPLACE FUNCTION public.app_is_privileged()
        RETURNS boolean
        LANGUAGE sql STABLE
        AS $fn$
            SELECT (
                public.app_es_proveedor()
                OR COALESCE(
                    ARRAY(SELECT jsonb_array_elements_text(public.app_jwt() -> 'roles'))
                        && ARRAY['coordinador','super-admin','proveedor']::text[],
                    false
                )
            )
            AND public.app_parroquia_activa()
        $fn$;

        -- fn_get_user: rechaza si la parroquia del usuario está inactiva.
        CREATE OR REPLACE FUNCTION public.fn_get_user()
        RETURNS jsonb
        LANGUAGE plpgsql
        STABLE SECURITY DEFINER
        SET search_path TO 'public'
        AS $fn$
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

            IF NOT _prov AND _u.parroquia_id IS NOT NULL
               AND NOT coalesce((SELECT activa FROM public.parroquias WHERE id = _u.parroquia_id), true) THEN
                RAISE EXCEPTION 'Tu parroquia está desactivada. Contacta al proveedor del sistema.'
                    USING ERRCODE = 'insufficient_privilege';
            END IF;

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
                       tipos_reunion, umbrales_alerta, procedencias, branding, roles_labels, ui
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
        $fn$;
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION public.app_is_privileged()
        RETURNS boolean
        LANGUAGE sql STABLE
        AS $fn$
            SELECT public.app_es_proveedor()
                OR COALESCE(
                    ARRAY(SELECT jsonb_array_elements_text(public.app_jwt() -> 'roles'))
                        && ARRAY['coordinador','super-admin','proveedor']::text[],
                    false
                )
        $fn$;
        DROP FUNCTION IF EXISTS public.app_parroquia_activa();
        SQL);
        // fn_get_user: la versión sin el chequeo queda para 2026_09_24_100000 si se
        // revierte más atrás; acá no la restauramos porque el chequeo es inocuo.
    }
};
