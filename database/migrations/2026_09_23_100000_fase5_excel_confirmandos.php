<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 5 — parte 4: import / export Excel de confirmandos.
 *
 * El parseo/generación del .xlsx vive en Edge Functions (Deno + SheetJS/ExcelJS).
 * La parte de BD va en RPCs SECURITY DEFINER (owner postgres) porque en staging
 * `service_role` no tiene privilegios de tabla:
 *
 *   fn_importar_confirmandos  ← ConfirmandoController::importar (insert masivo)
 *   fn_export_confirmandos    ← ConfirmandosPorGruposExport (datos ya agrupados)
 *
 * La Edge Function `importar-confirmandos` recibe el archivo, lo parsea, separa
 * "Apellido1 Apellido2 Nombre1 Nombre2", sanea y valida el celular (9 díg), y
 * pasa las filas limpias a la RPC. `exportar-confirmandos` toma el JSON de la RPC
 * y arma el libro con una hoja por grupo.
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
        -- Permisos efectivos del actor (rol + directos). Reutilizable.
        CREATE OR REPLACE FUNCTION public.app_actor_permisos(p_actor_auth uuid)
        RETURNS text[] LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
            SELECT coalesce(array_agg(DISTINCT name), '{}')
              FROM (
                SELECT pe.name
                  FROM public.users u
                  JOIN public.model_has_roles mhr
                    ON mhr.model_type = 'App\Models\User' AND mhr.model_id = u.id
                  JOIN public.role_has_permissions rhp ON rhp.role_id = mhr.role_id
                  JOIN public.permissions pe ON pe.id = rhp.permission_id AND pe.guard_name = 'api'
                 WHERE u.auth_id = p_actor_auth AND u.activo
                UNION
                SELECT pe.name
                  FROM public.users u
                  JOIN public.model_has_permissions mp
                    ON mp.model_type = 'App\Models\User' AND mp.model_id = u.id
                  JOIN public.permissions pe ON pe.id = mp.permission_id AND pe.guard_name = 'api'
                 WHERE u.auth_id = p_actor_auth AND u.activo
              ) x
        $$;

        -- ── Import ─────────────────────────────────────────────────────────
        -- p_filas: [{ nombres, apellidos, celular }] ya saneadas por la Edge Fn.
        CREATE OR REPLACE FUNCTION public.fn_importar_confirmandos(
            p_actor_auth uuid,
            p_filas      jsonb
        ) RETURNS jsonb
        LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
        DECLARE
            _pid  bigint;
            _perm text[];
            _n    int;
        BEGIN
            SELECT parroquia_id INTO _pid FROM public.users WHERE auth_id = p_actor_auth AND activo;
            IF _pid IS NULL THEN
                RAISE EXCEPTION 'Sin parroquia en el contexto' USING ERRCODE = 'insufficient_privilege';
            END IF;

            SELECT public.app_actor_permisos(p_actor_auth) INTO _perm;
            IF NOT ('crear confirmandos' = ANY(_perm)) THEN
                RAISE EXCEPTION 'No autorizado para importar confirmandos' USING ERRCODE = 'insufficient_privilege';
            END IF;

            INSERT INTO public.confirmandos
                (nombres, apellidos, celular, fecha_nacimiento, estado, parroquia_id, created_at, updated_at)
            SELECT left(f.nombres, 255), left(f.apellidos, 255),
                   nullif(f.celular, ''), NULL, 'en_preparacion', _pid, now(), now()
              FROM jsonb_to_recordset(p_filas) AS f(nombres text, apellidos text, celular text)
             WHERE coalesce(btrim(f.nombres), '') <> '' OR coalesce(btrim(f.apellidos), '') <> '';

            GET DIAGNOSTICS _n = ROW_COUNT;
            RETURN jsonb_build_object('importados', _n);
        END;
        $$;

        -- ── Export ─────────────────────────────────────────────────────────
        -- Devuelve los grupos de la parroquia del actor con catequistas +
        -- confirmandos + primer apoderado. Scope: parroquia (== el export de
        -- Laravel, que no filtra por grupo del catequista). La RLS igual acota.
        CREATE OR REPLACE FUNCTION public.fn_export_confirmandos(p_actor_auth uuid)
        RETURNS jsonb
        LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
        DECLARE
            _pid  bigint;
            _perm text[];
        BEGIN
            SELECT parroquia_id INTO _pid FROM public.users WHERE auth_id = p_actor_auth AND activo;
            IF _pid IS NULL THEN
                RAISE EXCEPTION 'Sin parroquia en el contexto' USING ERRCODE = 'insufficient_privilege';
            END IF;
            SELECT public.app_actor_permisos(p_actor_auth) INTO _perm;
            IF NOT ('ver confirmandos' = ANY(_perm) OR 'ver todos los confirmandos' = ANY(_perm)) THEN
                RAISE EXCEPTION 'No autorizado' USING ERRCODE = 'insufficient_privilege';
            END IF;

            RETURN jsonb_build_object(
                'grupos', coalesce((
                    SELECT jsonb_agg(jsonb_build_object(
                        'nombre', g.nombre,
                        'catequistas', coalesce((
                            SELECT jsonb_agg(u.name ORDER BY u.name)
                              FROM public.catequista_grupo cg JOIN public.users u ON u.id = cg.user_id
                             WHERE cg.grupo_id = g.id), '[]'::jsonb),
                        'confirmandos', public.app_export_filas(g.id, _pid)
                    ) ORDER BY g.nombre)
                    FROM public.grupos g WHERE g.parroquia_id = _pid
                ), '[]'::jsonb),
                'sin_grupo', public.app_export_filas(NULL, _pid)
            );
        END;
        $$;

        -- filas de confirmandos (de un grupo o sin grupo) para el export.
        CREATE OR REPLACE FUNCTION public.app_export_filas(p_grupo_id bigint, p_parroquia_id bigint)
        RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
            SELECT coalesce(jsonb_agg(jsonb_build_object(
                       'apellidos', c.apellidos, 'nombres', c.nombres,
                       'celular', c.celular, 'fecha_nacimiento', c.fecha_nacimiento,
                       'apoderado', (
                           SELECT jsonb_build_object(
                               'apellidos', a.apellidos, 'nombres', a.nombres,
                               'celular', a.celular, 'tipo', ta.nombre)
                             FROM public.confirmando_apoderado ca
                             JOIN public.apoderados a ON a.id = ca.apoderado_id
                             LEFT JOIN public.tipo_apoderados ta ON ta.id = ca.tipo_apoderado_id
                            WHERE ca.confirmando_id = c.id
                            ORDER BY ca.id LIMIT 1
                       )
                   ) ORDER BY c.apellidos), '[]'::jsonb)
              FROM public.confirmandos c
             WHERE c.parroquia_id = p_parroquia_id
               AND ((p_grupo_id IS NULL AND c.grupo_id IS NULL) OR c.grupo_id = p_grupo_id)
        $$;

        DO $$
        DECLARE _fn text;
        BEGIN
            FOREACH _fn IN ARRAY ARRAY[
                'app_actor_permisos(uuid)',
                'app_export_filas(bigint, bigint)',
                'fn_importar_confirmandos(uuid, jsonb)',
                'fn_export_confirmandos(uuid)'
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
            DROP FUNCTION IF EXISTS public.fn_importar_confirmandos(uuid, jsonb);
            DROP FUNCTION IF EXISTS public.fn_export_confirmandos(uuid);
            DROP FUNCTION IF EXISTS public.app_export_filas(bigint, bigint);
            DROP FUNCTION IF EXISTS public.app_actor_permisos(uuid);
            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
