<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stress test M2, A3, M8(sync) + endurecimiento del path de confirmandos.
 *
 * M2: `grupo_id` de otra parroquia no se validaba → referencia colgante cross-tenant.
 * A3: el permiso "editar confirmandos" del catequista era letra muerta (el RPC
 *     exigía app_is_privileged() y además la RLS de UPDATE lo bloqueaba).
 * M8: `fn_sync_*` toman advisory lock por grupo y rechazan ids ajenos.
 *
 * Para que el catequista pueda editar por el RPC (la RLS de UPDATE pide privilegio),
 * `fn_guardar_confirmando` y `fn_asignar_ruta_sacramental` pasan a SECURITY DEFINER
 * con scoping EXPLÍCITO por `parroquia_id` (patrón de fn_admin_* / fn_get_user).
 * Reglas de columna: el no-privilegiado solo edita datos básicos de confirmandos
 * DE SUS GRUPOS (no toca grupo, estado, ruta sacramental ni requisitos).
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
        -- ── fn_asignar_ruta_sacramental: DEFINER + scope explícito por parroquia
        CREATE OR REPLACE FUNCTION public.fn_asignar_ruta_sacramental(
            p_confirmando_id bigint,
            p_sacramento_faltante_id bigint
        ) RETURNS void
        LANGUAGE plpgsql
        SECURITY DEFINER SET search_path = public
        AS $fn$
        DECLARE
            _parroquia   bigint;
            _clave_falta text;
            _ord_falta   int;
            _sac_ids     bigint[];
            _pend_ids    bigint[];
            _req_ids     bigint[];
        BEGIN
            IF p_sacramento_faltante_id IS NULL THEN
                RETURN;
            END IF;

            SELECT parroquia_id INTO _parroquia FROM public.confirmandos WHERE id = p_confirmando_id;
            IF _parroquia IS NULL THEN
                RAISE EXCEPTION 'Confirmando % no encontrado', p_confirmando_id USING ERRCODE = 'no_data_found';
            END IF;

            SELECT array_agg(s.id) INTO _sac_ids
              FROM public.sacramentos s
             WHERE s.parroquia_id = _parroquia
               AND s.clave IN ('bautismo', 'comunion', 'confirmacion');
            IF _sac_ids IS NULL THEN
                RAISE EXCEPTION 'La parroquia no tiene configurada una ruta sacramental (Bautismo / Primera Comunión / Confirmación)'
                    USING ERRCODE = 'no_data_found';
            END IF;

            SELECT s.clave INTO _clave_falta
              FROM public.sacramentos s
             WHERE s.id = p_sacramento_faltante_id AND s.parroquia_id = _parroquia
               AND s.clave IN ('bautismo', 'comunion', 'confirmacion');
            IF _clave_falta IS NULL THEN
                RAISE EXCEPTION 'El sacramento indicado no pertenece a la ruta sacramental de esta parroquia'
                    USING ERRCODE = 'invalid_parameter_value';
            END IF;

            _ord_falta := CASE _clave_falta
                            WHEN 'bautismo' THEN 1 WHEN 'comunion' THEN 2 WHEN 'confirmacion' THEN 3 END;

            SELECT array_agg(s.id) INTO _pend_ids
              FROM public.sacramentos s
             WHERE s.parroquia_id = _parroquia AND s.clave IN ('bautismo', 'comunion', 'confirmacion')
               AND (CASE s.clave WHEN 'bautismo' THEN 1 WHEN 'comunion' THEN 2 WHEN 'confirmacion' THEN 3 END)
                   >= _ord_falta;

            INSERT INTO public.confirmando_sacramento (confirmando_id, sacramento_id, estado)
            SELECT p_confirmando_id, sid,
                   CASE WHEN sid = ANY(_pend_ids) THEN 'pendiente' ELSE 'recibido' END
              FROM unnest(_sac_ids) sid
            ON CONFLICT (confirmando_id, sacramento_id) DO UPDATE SET estado = EXCLUDED.estado;

            DELETE FROM public.confirmando_sacramento
             WHERE confirmando_id = p_confirmando_id AND sacramento_id <> ALL(_sac_ids);

            SELECT coalesce(array_agg(DISTINCT sr.requisito_id), '{}') INTO _req_ids
              FROM public.sacramento_requisito sr
             WHERE sr.sacramento_id = ANY(_pend_ids);

            INSERT INTO public.confirmando_requisito (confirmando_id, requisito_id, estado)
            SELECT p_confirmando_id, r, 'pendiente'
              FROM unnest(_req_ids) r
            ON CONFLICT (confirmando_id, requisito_id) DO NOTHING;

            DELETE FROM public.confirmando_requisito
             WHERE confirmando_id = p_confirmando_id AND requisito_id <> ALL(_req_ids);
        END;
        $fn$;
        REVOKE ALL ON FUNCTION public.fn_asignar_ruta_sacramental(bigint, bigint) FROM public;
        GRANT EXECUTE ON FUNCTION public.fn_asignar_ruta_sacramental(bigint, bigint) TO authenticated;

        -- ── fn_guardar_confirmando: DEFINER + scope explícito + permiso catequista
        CREATE OR REPLACE FUNCTION public.fn_guardar_confirmando(
            p_id bigint,
            p_datos jsonb,
            p_sacramento_faltante_id bigint DEFAULT NULL,
            p_apoderados jsonb DEFAULT NULL,
            p_requisitos jsonb DEFAULT NULL
        ) RETURNS bigint
        LANGUAGE plpgsql
        SECURITY DEFINER SET search_path = public
        AS $fn$
        DECLARE
            _id        bigint  := p_id;
            _parroquia bigint  := public.app_current_parroquia_id();
            _priv      boolean := public.app_is_privileged();
            _perms     text[]  := public.app_current_permisos();
            _grupo_in  text    := p_datos->>'grupo_id';
            _ap        jsonb;
            _ap_id     bigint;
            _req       jsonb;
        BEGIN
            IF _parroquia IS NULL THEN
                RAISE EXCEPTION 'Sin parroquia en el contexto' USING ERRCODE = 'invalid_parameter_value';
            END IF;

            -- ── Autorización ────────────────────────────────────────────────
            IF NOT _priv THEN
                IF _id IS NULL THEN
                    IF NOT ('crear confirmandos' = ANY(_perms)) THEN
                        RAISE EXCEPTION 'No autorizado para registrar confirmandos'
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                ELSE
                    IF NOT ('editar confirmandos' = ANY(_perms)) THEN
                        RAISE EXCEPTION 'No autorizado para editar confirmandos'
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM public.confirmandos
                                    WHERE id = _id AND parroquia_id = _parroquia
                                      AND grupo_id IN (SELECT public.app_user_grupo_ids())) THEN
                        RAISE EXCEPTION 'Solo puedes editar confirmandos de tus grupos'
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                END IF;
            END IF;

            -- ── grupo_id debe pertenecer a la parroquia ────────────────────
            IF _grupo_in IS NOT NULL AND _grupo_in <> ''
               AND NOT EXISTS (SELECT 1 FROM public.grupos
                                WHERE id = _grupo_in::bigint AND parroquia_id = _parroquia) THEN
                RAISE EXCEPTION 'El grupo indicado no pertenece a esta parroquia'
                    USING ERRCODE = 'invalid_parameter_value';
            END IF;

            -- ── 1. Confirmando ─────────────────────────────────────────────
            IF _id IS NULL THEN
                INSERT INTO public.confirmandos
                    (nombres, apellidos, celular, genero, fecha_nacimiento, grupo_id, estado, parroquia_id)
                VALUES (
                    p_datos->>'nombres',
                    p_datos->>'apellidos',
                    NULLIF(p_datos->>'celular', ''),
                    NULLIF(p_datos->>'genero', ''),
                    NULLIF(p_datos->>'fecha_nacimiento', '')::date,
                    NULLIF(p_datos->>'grupo_id', '')::bigint,
                    COALESCE(NULLIF(p_datos->>'estado', ''), 'en_preparacion'),
                    _parroquia
                )
                RETURNING id INTO _id;
            ELSE
                UPDATE public.confirmandos SET
                    nombres = CASE WHEN jsonb_exists(p_datos, 'nombres') AND p_datos->>'nombres' IS NOT NULL
                                   THEN p_datos->>'nombres' ELSE nombres END,
                    apellidos = CASE WHEN jsonb_exists(p_datos, 'apellidos') AND p_datos->>'apellidos' IS NOT NULL
                                     THEN p_datos->>'apellidos' ELSE apellidos END,
                    celular = CASE WHEN jsonb_exists(p_datos, 'celular')
                                   THEN NULLIF(p_datos->>'celular', '') ELSE celular END,
                    genero = CASE WHEN jsonb_exists(p_datos, 'genero')
                                  THEN NULLIF(p_datos->>'genero', '') ELSE genero END,
                    fecha_nacimiento = CASE WHEN jsonb_exists(p_datos, 'fecha_nacimiento')
                                            THEN NULLIF(p_datos->>'fecha_nacimiento', '')::date ELSE fecha_nacimiento END,
                    grupo_id = CASE WHEN _priv AND jsonb_exists(p_datos, 'grupo_id')
                                    THEN NULLIF(p_datos->>'grupo_id', '')::bigint ELSE grupo_id END,
                    estado = CASE WHEN _priv AND jsonb_exists(p_datos, 'estado') AND p_datos->>'estado' IS NOT NULL
                                  THEN p_datos->>'estado' ELSE estado END
                WHERE id = _id AND parroquia_id = _parroquia;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Confirmando % no encontrado', _id USING ERRCODE = 'no_data_found';
                END IF;
            END IF;

            -- ── 2. Ruta sacramental — solo privilegiado ────────────────────
            IF _priv AND p_sacramento_faltante_id IS NOT NULL THEN
                PERFORM public.fn_asignar_ruta_sacramental(_id, p_sacramento_faltante_id);
            END IF;

            -- ── 3. Apoderados — privilegiado o "editar confirmandos" ───────
            IF p_apoderados IS NOT NULL AND (_priv OR 'editar confirmandos' = ANY(_perms)) THEN
                DELETE FROM public.confirmando_apoderado WHERE confirmando_id = _id;

                FOR _ap IN SELECT * FROM jsonb_array_elements(p_apoderados)
                LOOP
                    SELECT id INTO _ap_id
                      FROM public.apoderados
                     WHERE nombres = _ap->>'nombres' AND apellidos = _ap->>'apellidos'
                       AND parroquia_id = _parroquia
                     ORDER BY id LIMIT 1;

                    IF _ap_id IS NULL THEN
                        INSERT INTO public.apoderados
                            (nombres, apellidos, celular, parroquia_id, created_at, updated_at)
                        VALUES (
                            _ap->>'nombres', _ap->>'apellidos',
                            NULLIF(_ap->>'celular', ''), _parroquia, now(), now()
                        )
                        RETURNING id INTO _ap_id;
                    ELSIF jsonb_exists(_ap, 'celular') THEN
                        UPDATE public.apoderados
                           SET celular = NULLIF(_ap->>'celular', ''), updated_at = now()
                         WHERE id = _ap_id;
                    END IF;

                    INSERT INTO public.confirmando_apoderado
                        (confirmando_id, apoderado_id, tipo_apoderado_id, created_at, updated_at)
                    VALUES (_id, _ap_id, (_ap->>'tipo_apoderado_id')::bigint, now(), now())
                    ON CONFLICT (confirmando_id, apoderado_id, tipo_apoderado_id) DO NOTHING;
                END LOOP;
            END IF;

            -- ── 4. Requisitos — solo privilegiado ─────────────────────────
            IF _priv AND p_requisitos IS NOT NULL THEN
                FOR _req IN SELECT * FROM jsonb_array_elements(p_requisitos)
                LOOP
                    UPDATE public.confirmando_requisito SET
                        estado = _req->>'estado',
                        fecha_entrega = CASE WHEN _req->>'estado' = 'entregado'
                                             THEN now()::date ELSE NULL END,
                        updated_at = now()
                    WHERE confirmando_id = _id
                      AND requisito_id = (_req->>'id')::bigint;
                END LOOP;
            END IF;

            RETURN _id;
        END;
        $fn$;
        REVOKE ALL ON FUNCTION public.fn_guardar_confirmando(bigint, jsonb, bigint, jsonb, jsonb) FROM public;
        GRANT EXECUTE ON FUNCTION public.fn_guardar_confirmando(bigint, jsonb, bigint, jsonb, jsonb) TO authenticated;

        -- ── fn_sync_confirmandos_grupo: lock + rechazo de ids ajenos ────────
        CREATE OR REPLACE FUNCTION public.fn_sync_confirmandos_grupo(p_grupo_id bigint, p_confirmando_ids bigint[])
        RETURNS bigint
        LANGUAGE plpgsql
        AS $fn$
        DECLARE _ids bigint[] := coalesce(p_confirmando_ids, '{}');
        BEGIN
            IF NOT public.app_is_privileged() THEN
                RAISE EXCEPTION 'No autorizado para asignar confirmandos' USING ERRCODE = 'insufficient_privilege';
            END IF;
            PERFORM pg_advisory_xact_lock(hashtext('grupo_sync:' || p_grupo_id));

            IF NOT EXISTS (SELECT 1 FROM public.grupos WHERE id = p_grupo_id) THEN
                RAISE EXCEPTION 'Grupo % no encontrado', p_grupo_id USING ERRCODE = 'no_data_found';
            END IF;
            IF EXISTS (SELECT unnest(_ids) EXCEPT SELECT id FROM public.confirmandos) THEN
                RAISE EXCEPTION 'Algún confirmando de la lista no pertenece a esta parroquia'
                    USING ERRCODE = 'invalid_parameter_value';
            END IF;

            UPDATE public.confirmandos SET grupo_id = NULL
             WHERE grupo_id = p_grupo_id AND id <> ALL(_ids);
            UPDATE public.confirmandos SET grupo_id = p_grupo_id
             WHERE id = ANY(_ids);

            RETURN p_grupo_id;
        END;
        $fn$;

        -- ── fn_sync_catequistas_grupo: lock + rechazo de ids ajenos ─────────
        CREATE OR REPLACE FUNCTION public.fn_sync_catequistas_grupo(p_grupo_id bigint, p_user_ids bigint[])
        RETURNS bigint
        LANGUAGE plpgsql
        AS $fn$
        DECLARE _ids bigint[] := coalesce(p_user_ids, '{}');
        BEGIN
            IF NOT public.app_is_privileged() THEN
                RAISE EXCEPTION 'No autorizado para asignar catequistas' USING ERRCODE = 'insufficient_privilege';
            END IF;
            PERFORM pg_advisory_xact_lock(hashtext('grupo_sync:' || p_grupo_id));

            IF NOT EXISTS (SELECT 1 FROM public.grupos WHERE id = p_grupo_id) THEN
                RAISE EXCEPTION 'Grupo % no encontrado', p_grupo_id USING ERRCODE = 'no_data_found';
            END IF;
            IF EXISTS (SELECT unnest(_ids) EXCEPT SELECT id FROM public.users) THEN
                RAISE EXCEPTION 'Algún catequista de la lista no pertenece a esta parroquia'
                    USING ERRCODE = 'invalid_parameter_value';
            END IF;

            DELETE FROM public.catequista_grupo
             WHERE grupo_id = p_grupo_id AND user_id <> ALL(_ids);

            INSERT INTO public.catequista_grupo (grupo_id, user_id, created_at, updated_at)
            SELECT p_grupo_id, u, now(), now()
              FROM unnest(_ids) AS u
            ON CONFLICT (grupo_id, user_id) DO NOTHING;

            RETURN p_grupo_id;
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
        DB::unprepared("NOTIFY pgrst, 'reload schema';");
    }
};
