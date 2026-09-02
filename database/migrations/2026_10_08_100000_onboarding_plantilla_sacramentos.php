<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Onboarding de parroquias — catálogo sacramental a partir de una PLANTILLA.
 *
 * 1) `parroquias.es_plantilla` (bool): marca la parroquia cuya ruta sacramental
 *    (sacramentos + documentos + qué documento pide cada sacramento) se copia al
 *    crear una parroquia nueva. Se marca la parroquia piloto (la más antigua).
 *    `fn_set_parroquia_plantilla(id)` cambia cuál es (solo proveedor, exclusiva).
 *    `v_parroquias` expone el flag.
 *
 * 2) `fn_crear_parroquia` gana `p_sacramentos text[]` (subconjunto de
 *    bautismo/comunion/confirmacion). Copia de la plantilla SOLO esos sacramentos
 *    y sus documentos; si no hay plantilla, cae al catálogo estándar embebido
 *    filtrado a esos sacramentos.
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
        ALTER TABLE public.parroquias
            ADD COLUMN IF NOT EXISTS es_plantilla boolean NOT NULL DEFAULT false;

        -- Marca la parroquia más antigua como plantilla si ninguna lo es aún.
        UPDATE public.parroquias SET es_plantilla = true
         WHERE id = (SELECT id FROM public.parroquias ORDER BY created_at, id LIMIT 1)
           AND NOT EXISTS (SELECT 1 FROM public.parroquias WHERE es_plantilla);

        -- v_parroquias: añade es_plantilla (DROP+CREATE: CREATE OR REPLACE no deja
        -- insertar columnas en medio)
        DROP VIEW IF EXISTS public.v_parroquias;
        CREATE VIEW public.v_parroquias WITH (security_invoker = on) AS
        SELECT p.id, p.nombre, p.slug, p.activa, p.zona_horaria, p.created_at,
               (SELECT count(*) FROM public.users u        WHERE u.parroquia_id = p.id) AS users_count,
               (SELECT count(*) FROM public.grupos g       WHERE g.parroquia_id = p.id) AS grupos_count,
               (SELECT count(*) FROM public.confirmandos c WHERE c.parroquia_id = p.id) AS confirmandos_count,
               p.es_plantilla
          FROM public.parroquias p;
        GRANT SELECT ON public.v_parroquias TO authenticated;

        -- Cambiar cuál parroquia es la plantilla (exclusiva). Solo proveedor.
        CREATE OR REPLACE FUNCTION public.fn_set_parroquia_plantilla(p_id bigint)
        RETURNS void
        LANGUAGE plpgsql
        SECURITY DEFINER SET search_path = public
        AS $fn$
        BEGIN
            IF NOT public.app_es_proveedor() THEN
                RAISE EXCEPTION 'Solo el proveedor puede definir la parroquia plantilla'
                    USING ERRCODE = 'insufficient_privilege';
            END IF;
            IF NOT EXISTS (SELECT 1 FROM public.parroquias WHERE id = p_id) THEN
                RAISE EXCEPTION 'Parroquia % no encontrada', p_id USING ERRCODE = 'no_data_found';
            END IF;
            UPDATE public.parroquias
               SET es_plantilla = (id = p_id), updated_at = now()
             WHERE es_plantilla OR id = p_id;
        END;
        $fn$;
        REVOKE ALL ON FUNCTION public.fn_set_parroquia_plantilla(bigint) FROM public, anon;
        GRANT EXECUTE ON FUNCTION public.fn_set_parroquia_plantilla(bigint) TO authenticated;

        -- ── fn_crear_parroquia: nueva firma con p_sacramentos ──────────────
        DROP FUNCTION IF EXISTS public.fn_crear_parroquia(uuid, text, text, text, text, text, text, uuid, text);

        CREATE OR REPLACE FUNCTION public.fn_crear_parroquia(
            p_actor_auth    uuid,
            p_nombre        text,
            p_slug          text,
            p_zona_horaria  text,
            p_admin_nombre  text,
            p_admin_email   text,
            p_admin_dni     text,
            p_admin_auth_id uuid,
            p_temp_password text,
            p_sacramentos   text[] DEFAULT ARRAY['bautismo','comunion','confirmacion']
        ) RETURNS jsonb
        LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $fn$
        DECLARE
            _pid    bigint;
            _uid    bigint;
            _slug   text;
            _tpl    bigint;
            _sacs   text[] := coalesce(p_sacramentos, ARRAY['bautismo','comunion','confirmacion']);
            _req    jsonb  := '{}'::jsonb;   -- nombre requisito -> id nuevo
            _sid    bigint;
            _rid    bigint;
            _r      record;
        BEGIN
            IF NOT public.app_actor_es_plataforma(p_actor_auth) THEN
                RAISE EXCEPTION 'No autorizado para crear parroquias' USING ERRCODE = 'insufficient_privilege';
            END IF;
            IF coalesce(btrim(p_nombre), '') = '' OR coalesce(btrim(p_admin_nombre), '') = ''
               OR coalesce(btrim(p_admin_email), '') = '' THEN
                RAISE EXCEPTION 'Nombre de parroquia, nombre y correo del admin son obligatorios' USING ERRCODE = 'check_violation';
            END IF;
            IF array_length(_sacs, 1) IS NULL THEN
                RAISE EXCEPTION 'Elige al menos un sacramento a gestionar' USING ERRCODE = 'check_violation';
            END IF;
            IF EXISTS (SELECT 1 FROM unnest(_sacs) s WHERE s NOT IN ('bautismo','comunion','confirmacion')) THEN
                RAISE EXCEPTION 'Sacramento no válido' USING ERRCODE = 'check_violation';
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

            SELECT id INTO _tpl FROM public.parroquias
             WHERE es_plantilla ORDER BY created_at, id LIMIT 1;

            IF _tpl IS NOT NULL AND _tpl <> _pid THEN
                -- ── Copia de la parroquia plantilla ───────────────────────
                -- 1) documentos que usan los sacramentos elegidos
                FOR _r IN
                    SELECT DISTINCT tr.nombre
                      FROM public.sacramento_requisito sr
                      JOIN public.sacramentos ts ON ts.id = sr.sacramento_id
                      JOIN public.requisitos  tr ON tr.id = sr.requisito_id
                     WHERE ts.parroquia_id = _tpl AND ts.clave = ANY(_sacs)
                LOOP
                    INSERT INTO public.requisitos (nombre, parroquia_id, created_at, updated_at)
                    VALUES (_r.nombre, _pid, now(), now())
                    RETURNING id INTO _rid;
                    _req := _req || jsonb_build_object(_r.nombre, _rid);
                END LOOP;

                -- 2) cada sacramento elegido + sus links
                FOR _r IN
                    SELECT ts.id AS tid, ts.nombre, ts.clave
                      FROM public.sacramentos ts
                     WHERE ts.parroquia_id = _tpl AND ts.clave = ANY(_sacs)
                     ORDER BY ts.clave
                LOOP
                    INSERT INTO public.sacramentos (nombre, clave, parroquia_id, created_at, updated_at)
                    VALUES (_r.nombre, _r.clave, _pid, now(), now())
                    RETURNING id INTO _sid;

                    INSERT INTO public.sacramento_requisito (sacramento_id, requisito_id, created_at, updated_at)
                    SELECT _sid, (_req ->> tr.nombre)::bigint, now(), now()
                      FROM public.sacramento_requisito sr
                      JOIN public.requisitos tr ON tr.id = sr.requisito_id
                     WHERE sr.sacramento_id = _r.tid;
                END LOOP;
            ELSE
                -- ── Fallback: catálogo estándar embebido ──────────────────
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
                    RETURNING id INTO _rid;
                    _req := _req || jsonb_build_object(_r.clave, _rid);
                END LOOP;

                FOR _r IN
                    SELECT * FROM (VALUES
                        ('bautismo',     'Bautismo',          ARRAY['acta_nacimiento','dni_confirmando','dni_apoderado']),
                        ('comunion',     'Primera Comunión',  ARRAY['partida_bautismo','dni_confirmando']),
                        ('confirmacion', 'Confirmación',      ARRAY['partida_bautismo','constancia_comunion','dni_confirmando','constancia_padrino','dni_padrino'])
                    ) AS x(clave, nombre, reqs)
                    WHERE x.clave = ANY(_sacs)
                LOOP
                    INSERT INTO public.sacramentos (nombre, clave, parroquia_id, created_at, updated_at)
                    VALUES (_r.nombre, _r.clave, _pid, now(), now())
                    RETURNING id INTO _sid;
                    INSERT INTO public.sacramento_requisito (sacramento_id, requisito_id, created_at, updated_at)
                    SELECT _sid, (_req ->> k)::bigint, now(), now() FROM unnest(_r.reqs) k;
                END LOOP;
            END IF;

            RETURN jsonb_build_object(
                'parroquia', (SELECT to_jsonb(v) FROM public.v_parroquias v WHERE v.id = _pid),
                'admin_email', lower(btrim(p_admin_email))
            );
        END;
        $fn$;

        DO $$
        DECLARE _fn text;
        BEGIN
            FOREACH _fn IN ARRAY ARRAY[
                'fn_crear_parroquia(uuid, text, text, text, text, text, text, uuid, text, text[])'
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
        DROP FUNCTION IF EXISTS public.fn_crear_parroquia(uuid, text, text, text, text, text, text, uuid, text, text[]);
        DROP FUNCTION IF EXISTS public.fn_set_parroquia_plantilla(bigint);

        DROP VIEW IF EXISTS public.v_parroquias;
        CREATE VIEW public.v_parroquias WITH (security_invoker = on) AS
        SELECT p.id, p.nombre, p.slug, p.activa, p.zona_horaria, p.created_at,
               (SELECT count(*) FROM public.users u        WHERE u.parroquia_id = p.id) AS users_count,
               (SELECT count(*) FROM public.grupos g       WHERE g.parroquia_id = p.id) AS grupos_count,
               (SELECT count(*) FROM public.confirmandos c WHERE c.parroquia_id = p.id) AS confirmandos_count
          FROM public.parroquias p;
        GRANT SELECT ON public.v_parroquias TO authenticated;

        ALTER TABLE public.parroquias DROP COLUMN IF EXISTS es_plantilla;
        NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
