<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Configuración por parroquia: sección `ui` (personalización de interfaz).
 *
 * Primera rebanada: `ui.dashboard_kpis` — qué tarjetas KPI muestra el panel.
 * Lista blanca: 'confirmandos', 'usuarios', 'grupos'. Ausente / null ⇒ el
 * frontend cae a "todas" (retrocompatible). Es un filtro de layout: un KPI se
 * ve solo si además el usuario tiene el permiso correspondiente.
 *
 *  - Nueva columna `ui jsonb NOT NULL DEFAULT '{}'` (las filas viejas la toman).
 *  - `fn_guardar_configuracion` valida y mergea `p_config->'ui'` (sin `ui` en el
 *    payload ⇒ no se toca; útil para clientes viejos).
 *  - `fn_get_user` agrega `ui` a la config que devuelve en el login.
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
            ADD COLUMN IF NOT EXISTS ui jsonb NOT NULL DEFAULT '{}'::jsonb;

        CREATE OR REPLACE FUNCTION public.fn_guardar_configuracion(p_config jsonb)
        RETURNS jsonb
        LANGUAGE plpgsql
        SECURITY INVOKER
        AS $$
        DECLARE
            _pid    bigint := public.app_current_parroquia_id();
            _inicio date   := nullif(p_config->>'programa_inicio', '')::date;
            _fin    date   := nullif(p_config->>'programa_fin', '')::date;
            _dias   int;
            _tipos  jsonb;
            _proc   jsonb;
            _roles  jsonb;
            _umb    jsonb  := p_config->'umbrales_alerta';
            _brand  jsonb  := p_config->'branding';
            _color  text   := p_config->'branding'->>'color_primario';
            _ui_in  jsonb  := p_config->'ui';
            _ui     jsonb;
            _kpis   jsonb;
            _k      text;
            _row    jsonb;
        BEGIN
            IF NOT public.app_is_privileged() THEN
                RAISE EXCEPTION 'No autorizado para editar la configuración'
                    USING ERRCODE = 'insufficient_privilege';
            END IF;
            IF _pid IS NULL THEN
                RAISE EXCEPTION 'Sin parroquia en el contexto'
                    USING ERRCODE = 'invalid_parameter_value';
            END IF;

            -- ── programa ────────────────────────────────────────────────────
            IF _fin IS NOT NULL AND _inicio IS NULL THEN
                RAISE EXCEPTION 'Indica la fecha de inicio del programa' USING ERRCODE = 'check_violation';
            END IF;
            IF _inicio IS NOT NULL AND _fin IS NOT NULL AND _fin < _inicio THEN
                RAISE EXCEPTION 'La fecha de fin no puede ser anterior a la de inicio' USING ERRCODE = 'check_violation';
            END IF;

            -- ── dias_ventana_justificacion ─────────────────────────────────
            BEGIN
                _dias := (p_config->>'dias_ventana_justificacion')::int;
            EXCEPTION WHEN others THEN
                _dias := NULL;
            END;
            IF _dias IS NULL OR _dias < 1 OR _dias > 365 THEN
                RAISE EXCEPTION 'Los días de ventana de justificación deben estar entre 1 y 365' USING ERRCODE = 'check_violation';
            END IF;

            -- ── tipos_reunion: dedup conservando orden, dominio, min 1 ──────
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

            -- ── umbrales_alerta: 5 claves, enteros 1..99 ───────────────────
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

            -- ── procedencias: trim + filtra vacíos + dedup con orden ────────
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

            -- ── branding ───────────────────────────────────────────────────
            IF _color IS NULL OR _color !~ '^#[0-9a-fA-F]{6}$' THEN
                RAISE EXCEPTION 'El color primario debe ser un hex de 6 dígitos (#RRGGBB)' USING ERRCODE = 'check_violation';
            END IF;
            IF length(coalesce(_brand->>'nombre_publico', '')) > 120 THEN
                RAISE EXCEPTION 'El nombre público admite hasta 120 caracteres' USING ERRCODE = 'check_violation';
            END IF;
            IF length(coalesce(_brand->>'logo_url', '')) > 500 THEN
                RAISE EXCEPTION 'La URL del logo admite hasta 500 caracteres' USING ERRCODE = 'check_violation';
            END IF;
            _brand := jsonb_build_object(
                'nombre_publico', nullif(_brand->>'nombre_publico', ''),
                'logo_url',       nullif(_brand->>'logo_url', ''),
                'color_primario', _color
            );

            -- ── roles_labels: filtra vacíos, <= 60 ─────────────────────────
            SELECT coalesce(jsonb_object_agg(key, value), '{}'::jsonb) INTO _roles
              FROM jsonb_each_text(coalesce(p_config->'roles_labels', '{}'::jsonb))
             WHERE trim(value) <> '';
            IF EXISTS (SELECT 1 FROM jsonb_each_text(_roles) WHERE length(value) > 60) THEN
                RAISE EXCEPTION 'Las etiquetas de rol admiten hasta 60 caracteres' USING ERRCODE = 'check_violation';
            END IF;

            -- ── ui: se mergea sobre lo que ya había (sin `ui` en el payload
            --    no se toca). Por ahora solo `dashboard_kpis`. ──────────────
            SELECT coalesce(ui, '{}'::jsonb) INTO _ui
              FROM public.parroquia_configuraciones WHERE parroquia_id = _pid;
            _ui := coalesce(_ui, '{}'::jsonb);

            IF _ui_in IS NOT NULL THEN
                IF jsonb_typeof(_ui_in) <> 'object' THEN
                    RAISE EXCEPTION 'La sección "ui" debe ser un objeto' USING ERRCODE = 'check_violation';
                END IF;

                IF _ui_in ? 'dashboard_kpis' THEN
                    _kpis := _ui_in->'dashboard_kpis';
                    IF jsonb_typeof(_kpis) <> 'array' THEN
                        RAISE EXCEPTION 'dashboard_kpis debe ser una lista' USING ERRCODE = 'check_violation';
                    END IF;
                    IF EXISTS (SELECT 1 FROM jsonb_array_elements_text(_kpis) v
                                WHERE v NOT IN ('confirmandos', 'usuarios', 'grupos')) THEN
                        RAISE EXCEPTION 'KPI de dashboard no válido' USING ERRCODE = 'check_violation';
                    END IF;
                    -- dedup conservando orden
                    SELECT jsonb_agg(x ORDER BY ord) INTO _kpis
                      FROM (SELECT v AS x, min(ord) AS ord
                              FROM jsonb_array_elements_text(_kpis)
                                   WITH ORDINALITY AS e(v, ord)
                             GROUP BY v) g;
                    _ui := jsonb_set(_ui, '{dashboard_kpis}', coalesce(_kpis, '[]'::jsonb));
                END IF;
            END IF;

            INSERT INTO public.parroquia_configuraciones AS pc
                (parroquia_id, programa_inicio, programa_fin, dias_ventana_justificacion,
                 tipos_reunion, umbrales_alerta, procedencias, branding, roles_labels, ui,
                 created_at, updated_at)
            VALUES
                (_pid, _inicio, _fin, _dias, _tipos, _umb, _proc, _brand, _roles, _ui, now(), now())
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
                    'ui',                         _row->'ui'
                )
            );
        END;
        $$;

        REVOKE ALL ON FUNCTION public.fn_guardar_configuracion(jsonb) FROM public;
        GRANT EXECUTE ON FUNCTION public.fn_guardar_configuracion(jsonb) TO authenticated;

        NOTIFY pgrst, 'reload schema';
        SQL);

        // fn_get_user: agregar `ui` a la config devuelta en el login.
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
        $$;

        REVOKE ALL ON FUNCTION public.fn_get_user() FROM public;
        GRANT EXECUTE ON FUNCTION public.fn_get_user() TO authenticated;

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Se revierte solo la exposición de `ui`; la columna se conserva (barata,
        // y quitarla rompería fn_guardar_configuracion si quedó una versión nueva).
        DB::unprepared("NOTIFY pgrst, 'reload schema';");
    }
};
