<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stress test A1 (parte config) y M5.
 *
 * A1: el rango de edad para el reparto de grupos estaba hardcodeado (14–18) y
 *     excluía confirmandos en silencio. Pasa a `parroquia_configuraciones`
 *     (`grupos_edad_min` / `grupos_edad_max`, NULL = sin límite). Default NULL:
 *     el generador deja de excluir por edad hasta que la parroquia lo configure.
 *
 * M5: `fn_get_user` no devolvía `zona_horaria` → el frontend calculaba "ahora"
 *     con la hora del navegador y discrepaba con el backend en "¿ya empezó la
 *     reunión?". Ahora la incluye en el objeto `parroquia`.
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
        ALTER TABLE public.parroquia_configuraciones
            ADD COLUMN IF NOT EXISTS grupos_edad_min smallint,
            ADD COLUMN IF NOT EXISTS grupos_edad_max smallint;

        ALTER TABLE public.parroquia_configuraciones
            DROP CONSTRAINT IF EXISTS parroquia_config_edad_chk;
        ALTER TABLE public.parroquia_configuraciones
            ADD CONSTRAINT parroquia_config_edad_chk CHECK (
                (grupos_edad_min IS NULL OR grupos_edad_min BETWEEN 1 AND 99)
            AND (grupos_edad_max IS NULL OR grupos_edad_max BETWEEN 1 AND 99)
            AND (grupos_edad_min IS NULL OR grupos_edad_max IS NULL OR grupos_edad_min <= grupos_edad_max)
            );

        -- ── fn_guardar_configuracion: valida y persiste el rango de edad ──────
        CREATE OR REPLACE FUNCTION public.fn_guardar_configuracion(p_config jsonb)
        RETURNS jsonb
        LANGUAGE plpgsql
        SECURITY INVOKER
        AS $fn$
        DECLARE
            _pid        bigint := public.app_current_parroquia_id();
            _inicio     date   := nullif(p_config->>'programa_inicio', '')::date;
            _fin        date   := nullif(p_config->>'programa_fin', '')::date;
            _dias       int;
            _tipos      jsonb;
            _proc       jsonb;
            _roles      jsonb;
            _umb        jsonb  := p_config->'umbrales_alerta';
            _brand      jsonb  := p_config->'branding';
            _color      text   := p_config->'branding'->>'color_primario';
            _logo_prov  text;
            _emin       int;
            _emax       int;
            _ui         jsonb;
            _k          text;
            _row        jsonb;
        BEGIN
            IF NOT public.app_is_privileged() THEN
                RAISE EXCEPTION 'No autorizado para editar la configuración'
                    USING ERRCODE = 'insufficient_privilege';
            END IF;
            IF _pid IS NULL THEN
                RAISE EXCEPTION 'Sin parroquia en el contexto'
                    USING ERRCODE = 'invalid_parameter_value';
            END IF;

            -- Serializa guardados concurrentes de la misma parroquia (M8).
            PERFORM pg_advisory_xact_lock(hashtext('config:' || _pid));

            IF _fin IS NOT NULL AND _inicio IS NULL THEN
                RAISE EXCEPTION 'Indica la fecha de inicio del programa' USING ERRCODE = 'check_violation';
            END IF;
            IF _inicio IS NOT NULL AND _fin IS NOT NULL AND _fin < _inicio THEN
                RAISE EXCEPTION 'La fecha de fin no puede ser anterior a la de inicio' USING ERRCODE = 'check_violation';
            END IF;

            BEGIN
                _dias := (p_config->>'dias_ventana_justificacion')::int;
            EXCEPTION WHEN others THEN
                _dias := NULL;
            END;
            IF _dias IS NULL OR _dias < 1 OR _dias > 365 THEN
                RAISE EXCEPTION 'Los días de ventana de justificación deben estar entre 1 y 365' USING ERRCODE = 'check_violation';
            END IF;

            -- ── rango de edad para el reparto de grupos (opcional) ──────────
            _emin := nullif(p_config->>'grupos_edad_min', '')::int;
            _emax := nullif(p_config->>'grupos_edad_max', '')::int;
            IF (_emin IS NOT NULL AND (_emin < 1 OR _emin > 99))
               OR (_emax IS NOT NULL AND (_emax < 1 OR _emax > 99))
               OR (_emin IS NOT NULL AND _emax IS NOT NULL AND _emin > _emax) THEN
                RAISE EXCEPTION 'El rango de edad para grupos debe estar entre 1 y 99, con mínimo ≤ máximo'
                    USING ERRCODE = 'check_violation';
            END IF;

            SELECT jsonb_agg(x ORDER BY ord) INTO _tipos
              FROM (SELECT v AS x, min(ord) AS ord
                      FROM jsonb_array_elements_text(coalesce(p_config->'tipos_reunion', '[]'::jsonb))
                           WITH ORDINALITY AS e(v, ord)
                     GROUP BY v) g;
            IF _tipos IS NULL OR jsonb_array_length(_tipos) < 1 THEN
                RAISE EXCEPTION 'Selecciona al menos un tipo de reunión' USING ERRCODE = 'check_violation';
            END IF;
            IF EXISTS (SELECT 1 FROM jsonb_array_elements_text(_tipos) t
                        WHERE t NOT IN ('Confirmandos', 'Catequistas', 'Apoderados')) THEN
                RAISE EXCEPTION 'Tipo de reunión no válido' USING ERRCODE = 'check_violation';
            END IF;

            IF _umb IS NULL OR jsonb_typeof(_umb) <> 'object' THEN
                RAISE EXCEPTION 'Faltan los umbrales de alerta' USING ERRCODE = 'check_violation';
            END IF;
            FOREACH _k IN ARRAY ARRAY['alto_injustificadas', 'alto_racha',
                                      'alto_seguidas_historicas', 'medio_justificadas',
                                      'bajo_tardanzas_seguidas']
            LOOP
                IF _umb->>_k IS NULL OR (_umb->>_k) !~ '^[0-9]+$'
                   OR (_umb->>_k)::int < 1 OR (_umb->>_k)::int > 99 THEN
                    RAISE EXCEPTION 'El umbral "%" debe ser un entero entre 1 y 99', _k USING ERRCODE = 'check_violation';
                END IF;
            END LOOP;
            SELECT jsonb_object_agg(key, value::int) INTO _umb FROM jsonb_each_text(_umb);

            SELECT jsonb_agg(x ORDER BY ord) INTO _proc
              FROM (SELECT trim(v) AS x, min(ord) AS ord
                      FROM jsonb_array_elements_text(coalesce(p_config->'procedencias', '[]'::jsonb))
                           WITH ORDINALITY AS e(v, ord)
                     GROUP BY trim(v)) g
             WHERE x <> '';
            IF _proc IS NULL OR jsonb_array_length(_proc) < 1 THEN
                RAISE EXCEPTION 'Indica al menos una procedencia' USING ERRCODE = 'check_violation';
            END IF;
            IF EXISTS (SELECT 1 FROM jsonb_array_elements_text(_proc) v WHERE length(v) > 30) THEN
                RAISE EXCEPTION 'Cada procedencia admite hasta 30 caracteres' USING ERRCODE = 'check_violation';
            END IF;

            IF _color IS NULL OR _color !~ '^#[0-9a-fA-F]{6}$' THEN
                RAISE EXCEPTION 'El color primario debe ser un hex de 6 dígitos (#RRGGBB)' USING ERRCODE = 'check_violation';
            END IF;
            IF length(coalesce(_brand->>'nombre_publico', '')) > 120 THEN
                RAISE EXCEPTION 'El nombre público admite hasta 120 caracteres' USING ERRCODE = 'check_violation';
            END IF;
            IF length(coalesce(_brand->>'logo_url', '')) > 1000 THEN
                RAISE EXCEPTION 'La URL del logo admite hasta 1000 caracteres' USING ERRCODE = 'check_violation';
            END IF;
            SELECT branding->>'logo_url_proveedor' INTO _logo_prov
              FROM public.parroquia_configuraciones WHERE parroquia_id = _pid;
            _brand := jsonb_build_object(
                'nombre_publico',     nullif(_brand->>'nombre_publico', ''),
                'logo_url',           nullif(_brand->>'logo_url', ''),
                'logo_url_proveedor', _logo_prov,
                'color_primario',     _color
            );

            SELECT coalesce(jsonb_object_agg(key, value), '{}'::jsonb) INTO _roles
              FROM jsonb_each_text(coalesce(p_config->'roles_labels', '{}'::jsonb))
             WHERE trim(value) <> '';
            IF EXISTS (SELECT 1 FROM jsonb_each_text(_roles) WHERE length(value) > 60) THEN
                RAISE EXCEPTION 'Las etiquetas de rol admiten hasta 60 caracteres' USING ERRCODE = 'check_violation';
            END IF;

            SELECT coalesce(ui, '{}'::jsonb) INTO _ui
              FROM public.parroquia_configuraciones WHERE parroquia_id = _pid;
            _ui := public._ui_procesar(_ui, p_config->'ui');

            INSERT INTO public.parroquia_configuraciones AS pc
                (parroquia_id, programa_inicio, programa_fin, dias_ventana_justificacion,
                 tipos_reunion, umbrales_alerta, procedencias, branding, roles_labels, ui,
                 grupos_edad_min, grupos_edad_max, created_at, updated_at)
            VALUES
                (_pid, _inicio, _fin, _dias, _tipos, _umb, _proc, _brand, _roles, _ui,
                 _emin, _emax, now(), now())
            ON CONFLICT (parroquia_id) DO UPDATE SET
                programa_inicio            = EXCLUDED.programa_inicio,
                programa_fin               = EXCLUDED.programa_fin,
                dias_ventana_justificacion = EXCLUDED.dias_ventana_justificacion,
                tipos_reunion              = EXCLUDED.tipos_reunion,
                umbrales_alerta            = EXCLUDED.umbrales_alerta,
                procedencias               = EXCLUDED.procedencias,
                branding                   = EXCLUDED.branding,
                roles_labels               = EXCLUDED.roles_labels,
                ui                         = EXCLUDED.ui,
                grupos_edad_min            = EXCLUDED.grupos_edad_min,
                grupos_edad_max            = EXCLUDED.grupos_edad_max,
                updated_at                 = now()
            RETURNING to_jsonb(pc) INTO _row;

            RETURN jsonb_build_object(
                'message', 'Configuración actualizada',
                'configuracion', jsonb_build_object(
                    'programa_inicio',            _row->>'programa_inicio',
                    'programa_fin',               _row->>'programa_fin',
                    'dias_ventana_justificacion', (_row->>'dias_ventana_justificacion')::int,
                    'tipos_reunion',              _row->'tipos_reunion',
                    'umbrales_alerta',            _row->'umbrales_alerta',
                    'procedencias',               _row->'procedencias',
                    'branding',                   _row->'branding',
                    'roles_labels',               _row->'roles_labels',
                    'ui',                         _row->'ui',
                    'grupos_edad_min',            (_row->>'grupos_edad_min')::int,
                    'grupos_edad_max',            (_row->>'grupos_edad_max')::int
                )
            );
        END;
        $fn$;

        REVOKE ALL ON FUNCTION public.fn_guardar_configuracion(jsonb) FROM public;
        GRANT EXECUTE ON FUNCTION public.fn_guardar_configuracion(jsonb) TO authenticated;

        -- ── fn_get_user: incluir zona_horaria + rango de edad en la config ──
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
                       tipos_reunion, umbrales_alerta, procedencias, branding, roles_labels, ui,
                       grupos_edad_min, grupos_edad_max
                  FROM public.parroquia_configuraciones WHERE parroquia_id = _u.parroquia_id
            ) c;

            RETURN jsonb_build_object(
                'id', _u.id, 'name', _u.name, 'email', _u.email, 'dni', _u.dni,
                'roles', to_jsonb(_roles),
                'permissions', to_jsonb(_perms),
                'parroquia', CASE WHEN _prov OR _u.parroquia_id IS NULL THEN NULL ELSE (
                    SELECT jsonb_build_object('id', p.id, 'slug', p.slug, 'nombre', p.nombre,
                                              'zona_horaria', p.zona_horaria)
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

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
        ALTER TABLE public.parroquia_configuraciones DROP CONSTRAINT IF EXISTS parroquia_config_edad_chk;
        ALTER TABLE public.parroquia_configuraciones
            DROP COLUMN IF EXISTS grupos_edad_min,
            DROP COLUMN IF EXISTS grupos_edad_max;
        NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
