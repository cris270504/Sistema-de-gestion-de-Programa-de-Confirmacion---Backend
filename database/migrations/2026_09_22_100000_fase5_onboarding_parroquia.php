<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 5 — parte 3: panel del proveedor (alta y administración de parroquias).
 *
 *   v_parroquias                  ← ProveedorParroquiaController::index (con counts)
 *   fn_crear_parroquia            ← ::store (parroquia + config + super-admin + catálogo)
 *   UPDATE directo por PostgREST  ← ::update
 *
 * El alta crea un `auth.users` (el admin de la parroquia), así que la orquesta la
 * Edge Function `onboarding-parroquia`; `fn_crear_parroquia` hace la parte de BD
 * en una transacción y solo la ejecuta `service_role`.
 *
 * Todo gateado a "administrar plataforma" / proveedor.
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
        -- ── Lectura: parroquias con conteos ────────────────────────────────
        CREATE OR REPLACE VIEW public.v_parroquias WITH (security_invoker = on) AS
        SELECT p.id, p.nombre, p.slug, p.activa, p.zona_horaria, p.created_at,
               (SELECT count(*) FROM public.users u        WHERE u.parroquia_id = p.id) AS users_count,
               (SELECT count(*) FROM public.grupos g       WHERE g.parroquia_id = p.id) AS grupos_count,
               (SELECT count(*) FROM public.confirmandos c WHERE c.parroquia_id = p.id) AS confirmandos_count
          FROM public.parroquias p;

        GRANT SELECT ON public.v_parroquias TO authenticated;
        -- update de parroquia por PostgREST (RLS parroquias_write = app_es_proveedor)
        GRANT UPDATE ON public.parroquias TO authenticated;

        -- ── ¿el actor administra la plataforma? ────────────────────────────
        CREATE OR REPLACE FUNCTION public.app_actor_es_plataforma(p_actor_auth uuid)
        RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
            SELECT EXISTS (
                SELECT 1 FROM public.users u
                 WHERE u.auth_id = p_actor_auth AND u.activo
                   AND (
                       EXISTS (SELECT 1 FROM public.model_has_roles m JOIN public.roles r ON r.id = m.role_id
                                WHERE m.model_type = 'App\Models\User' AND m.model_id = u.id AND r.name = 'proveedor')
                    OR EXISTS (SELECT 1 FROM public.model_has_permissions mp JOIN public.permissions pe ON pe.id = mp.permission_id
                                WHERE mp.model_type = 'App\Models\User' AND mp.model_id = u.id AND pe.name = 'administrar plataforma')
                    OR EXISTS (SELECT 1 FROM public.model_has_roles m
                                JOIN public.role_has_permissions rhp ON rhp.role_id = m.role_id
                                JOIN public.permissions pe ON pe.id = rhp.permission_id
                                WHERE m.model_type = 'App\Models\User' AND m.model_id = u.id AND pe.name = 'administrar plataforma')
                   )
            )
        $$;

        -- ── Alta de parroquia (transaccional) ──────────────────────────────
        CREATE OR REPLACE FUNCTION public.fn_crear_parroquia(
            p_actor_auth    uuid,
            p_nombre        text,
            p_slug          text,
            p_zona_horaria  text,
            p_admin_nombre  text,
            p_admin_email   text,
            p_admin_dni     text,
            p_admin_auth_id uuid,
            p_temp_password text
        ) RETURNS jsonb
        LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
        DECLARE
            _pid   bigint;
            _uid   bigint;
            _slug  text;
            _req   jsonb := '{}'::jsonb;   -- clave -> id
            _sid   bigint;
            _r     record;
        BEGIN
            IF NOT public.app_actor_es_plataforma(p_actor_auth) THEN
                RAISE EXCEPTION 'No autorizado para crear parroquias' USING ERRCODE = 'insufficient_privilege';
            END IF;
            IF coalesce(btrim(p_nombre), '') = '' OR coalesce(btrim(p_admin_nombre), '') = ''
               OR coalesce(btrim(p_admin_email), '') = '' THEN
                RAISE EXCEPTION 'Nombre de parroquia, nombre y correo del admin son obligatorios' USING ERRCODE = 'check_violation';
            END IF;

            _slug := coalesce(
                nullif(btrim(p_slug), ''),
                btrim(regexp_replace(
                    translate(lower(btrim(p_nombre)),
                              'áàäâãéèëêíìïîóòöôõúùüûñç', 'aaaaaeeeeiiiiooooouuuunc'),
                    '[^a-z0-9]+', '-', 'g'
                ), '-') || '-' || substr(md5(random()::text), 1, 4)
            );

            INSERT INTO public.parroquias (nombre, slug, zona_horaria, activa, created_at, updated_at)
            VALUES (btrim(p_nombre), _slug, coalesce(nullif(btrim(p_zona_horaria), ''), 'America/Lima'),
                    true, now(), now())
            RETURNING id INTO _pid;

            INSERT INTO public.parroquia_configuraciones
                (parroquia_id, dias_ventana_justificacion, tipos_reunion, umbrales_alerta,
                 procedencias, branding, roles_labels, created_at, updated_at)
            VALUES (
                _pid, 21,
                '["Confirmandos","Catequistas","Apoderados"]'::json,
                '{"alto_injustificadas":4,"alto_racha":2,"alto_seguidas_historicas":3,"medio_justificadas":4,"bajo_tardanzas_seguidas":2}'::json,
                '["sede","caserio"]'::json,
                json_build_object('nombre_publico', btrim(p_nombre), 'logo_url', null, 'color_primario', '#2563eb'),
                '{}'::json, now(), now()
            );

            INSERT INTO public.users
                (name, dni, email, password, parroquia_id, auth_id, activo, created_at, updated_at)
            VALUES (
                btrim(p_admin_nombre), nullif(btrim(p_admin_dni), ''), lower(btrim(p_admin_email)),
                extensions.crypt(p_temp_password, extensions.gen_salt('bf')),
                _pid, p_admin_auth_id, true, now(), now()
            )
            RETURNING id INTO _uid;

            INSERT INTO public.model_has_roles (role_id, model_type, model_id)
            SELECT r.id, 'App\Models\User', _uid FROM public.roles r
             WHERE r.guard_name = 'api' AND r.name = 'super-admin';

            -- ── Catálogo sacramental estándar (SembrarCatalogoSacramental) ──
            FOR _r IN
                SELECT * FROM (VALUES
                    ('acta_nacimiento',    'Acta de nacimiento del confirmando'),
                    ('dni_confirmando',    'Copia de DNI del confirmando'),
                    ('dni_apoderado',      'Copia de DNI de los apoderados'),
                    ('partida_bautismo',   'Partida de Bautismo'),
                    ('constancia_comunion','Constancia de Primera Comunión'),
                    ('constancia_padrino', 'Constancia de Confirmación o Matrimonio del padrino/madrina'),
                    ('dni_padrino',        'Copia de DNI del padrino/madrina')
                ) AS x(clave, nombre)
            LOOP
                INSERT INTO public.requisitos (nombre, parroquia_id, created_at, updated_at)
                VALUES (_r.nombre, _pid, now(), now())
                RETURNING id INTO _sid;
                _req := _req || jsonb_build_object(_r.clave, _sid);
            END LOOP;

            FOR _r IN
                SELECT * FROM (VALUES
                    ('bautismo',     'Bautismo',          ARRAY['acta_nacimiento','dni_confirmando','dni_apoderado']),
                    ('comunion',     'Primera Comunión',  ARRAY['partida_bautismo','dni_confirmando']),
                    ('confirmacion', 'Confirmación',      ARRAY['partida_bautismo','constancia_comunion','dni_confirmando','constancia_padrino','dni_padrino'])
                ) AS x(clave, nombre, reqs)
            LOOP
                INSERT INTO public.sacramentos (nombre, clave, parroquia_id, created_at, updated_at)
                VALUES (_r.nombre, _r.clave, _pid, now(), now())
                RETURNING id INTO _sid;
                INSERT INTO public.sacramento_requisito (sacramento_id, requisito_id, created_at, updated_at)
                SELECT _sid, (_req ->> k)::bigint, now(), now() FROM unnest(_r.reqs) k;
            END LOOP;

            RETURN jsonb_build_object(
                'parroquia', (SELECT to_jsonb(v) FROM public.v_parroquias v WHERE v.id = _pid),
                'admin_email', lower(btrim(p_admin_email))
            );
        END;
        $$;

        DO $$
        DECLARE _fn text;
        BEGIN
            FOREACH _fn IN ARRAY ARRAY[
                'app_actor_es_plataforma(uuid)',
                'fn_crear_parroquia(uuid, text, text, text, text, text, text, uuid, text)'
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
            DROP VIEW IF EXISTS public.v_parroquias;
            DROP FUNCTION IF EXISTS public.fn_crear_parroquia(uuid, text, text, text, text, text, text, uuid, text);
            DROP FUNCTION IF EXISTS public.app_actor_es_plataforma(uuid);
            REVOKE UPDATE ON public.parroquias FROM authenticated;
            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
