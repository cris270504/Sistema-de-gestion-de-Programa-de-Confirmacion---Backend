<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Branding — logo con dos ranuras.
 *
 *   branding.logo_url_proveedor  ← lo pone el proveedor al crear/editar la parroquia (base)
 *   branding.logo_url            ← lo pone el admin de la parroquia (override)
 *
 * El logo efectivo que muestra el sistema = logo_url → logo_url_proveedor → default.
 *
 * 1) fn_branding_logo_set(parroquia_id, slot, url): escribe SOLO su clave del jsonb.
 *    - slot 'proveedor'  exige app_es_proveedor()
 *    - slot 'parroquia'  exige app_is_privileged() sobre la parroquia propia
 *    - url NULL limpia la ranura; si no, debe apuntar al Storage público del proyecto.
 *    SECURITY DEFINER: la subida del archivo la gatea la RLS de storage.objects; acá
 *    solo se persiste la URL ya resuelta.
 *
 * 2) fn_guardar_configuracion: el bloque `branding` preserva logo_url_proveedor
 *    (el admin nunca lo manda) y admite hasta 1000 caracteres en logo_url.
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
        CREATE OR REPLACE FUNCTION public.fn_branding_logo_set(
            p_parroquia_id bigint,
            p_slot         text,
            p_url          text
        ) RETURNS jsonb
        LANGUAGE plpgsql
        SECURITY DEFINER SET search_path = public
        AS $$
        DECLARE
            _key   text;
            _url   text := nullif(btrim(p_url), '');
            _brand json;   -- parroquia_configuraciones.branding es de tipo json
        BEGIN
            IF p_slot NOT IN ('proveedor', 'parroquia') THEN
                RAISE EXCEPTION 'Ranura de logo no válida' USING ERRCODE = 'check_violation';
            END IF;

            IF p_slot = 'proveedor' THEN
                IF NOT public.app_es_proveedor() THEN
                    RAISE EXCEPTION 'Solo el proveedor puede cambiar el logo base'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
                _key := 'logo_url_proveedor';
            ELSE
                IF NOT public.app_is_privileged()
                   OR public.app_current_parroquia_id() IS DISTINCT FROM p_parroquia_id THEN
                    RAISE EXCEPTION 'No autorizado para el logo de esta parroquia'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
                _key := 'logo_url';
            END IF;

            IF _url IS NOT NULL THEN
                IF length(_url) > 1000 THEN
                    RAISE EXCEPTION 'La URL del logo es demasiado larga' USING ERRCODE = 'check_violation';
                END IF;
                IF _url !~ '^https://[a-z0-9-]+\.supabase\.co/storage/v1/object/public/branding/' THEN
                    RAISE EXCEPTION 'La URL del logo no corresponde al almacenamiento del sistema'
                        USING ERRCODE = 'check_violation';
                END IF;
            END IF;

            UPDATE public.parroquia_configuraciones
               SET branding    = (coalesce(branding::jsonb, '{}'::jsonb)
                                  || jsonb_build_object(_key, _url))::json,
                   updated_at  = now()
             WHERE parroquia_id = p_parroquia_id
            RETURNING branding INTO _brand;

            IF _brand IS NULL THEN
                RAISE EXCEPTION 'La parroquia no tiene configuración' USING ERRCODE = 'no_data_found';
            END IF;

            RETURN jsonb_build_object('branding', _brand);
        END;
        $$;

        REVOKE ALL ON FUNCTION public.fn_branding_logo_set(bigint, text, text) FROM public;
        GRANT EXECUTE ON FUNCTION public.fn_branding_logo_set(bigint, text, text) TO authenticated;

        -- ── fn_guardar_configuracion: branding preserva logo_url_proveedor ──────
        CREATE OR REPLACE FUNCTION public.fn_guardar_configuracion(p_config jsonb)
        RETURNS jsonb
        LANGUAGE plpgsql
        SECURITY INVOKER
        AS $$
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
            IF length(coalesce(_brand->>'logo_url', '')) > 1000 THEN
                RAISE EXCEPTION 'La URL del logo admite hasta 1000 caracteres' USING ERRCODE = 'check_violation';
            END IF;
            -- El logo base del proveedor no viaja en el payload del admin: se conserva.
            SELECT branding->>'logo_url_proveedor' INTO _logo_prov
              FROM public.parroquia_configuraciones WHERE parroquia_id = _pid;
            _brand := jsonb_build_object(
                'nombre_publico',     nullif(_brand->>'nombre_publico', ''),
                'logo_url',           nullif(_brand->>'logo_url', ''),
                'logo_url_proveedor', _logo_prov,
                'color_primario',     _color
            );

            -- ── roles_labels: filtra vacíos, <= 60 ─────────────────────────
            SELECT coalesce(jsonb_object_agg(key, value), '{}'::jsonb) INTO _roles
              FROM jsonb_each_text(coalesce(p_config->'roles_labels', '{}'::jsonb))
             WHERE trim(value) <> '';
            IF EXISTS (SELECT 1 FROM jsonb_each_text(_roles) WHERE length(value) > 60) THEN
                RAISE EXCEPTION 'Las etiquetas de rol admiten hasta 60 caracteres' USING ERRCODE = 'check_violation';
            END IF;

            -- ── ui: todo el manejo vive en _ui_procesar ────────────────────
            SELECT coalesce(ui, '{}'::jsonb) INTO _ui
              FROM public.parroquia_configuraciones WHERE parroquia_id = _pid;
            _ui := public._ui_procesar(_ui, p_config->'ui');

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
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
        DROP FUNCTION IF EXISTS public.fn_branding_logo_set(bigint, text, text);
        NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
