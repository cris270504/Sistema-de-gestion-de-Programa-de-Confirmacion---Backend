<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 — parte 3: alta/edición de confirmando con relaciones anidadas como RPC
 * (plpgsql, SECURITY INVOKER → la RLS de cada tabla aplica).
 *
 * fn_guardar_confirmando ← ConfirmandoController::store + update
 *   (+ syncApoderados + asignarRutaSacramental + requisitos_actualizar).
 *
 * - p_datos parcial: en edición solo se tocan las claves presentes (igual que el
 *   `$data['x'] ?? $confirmando->x` de Eloquent). El modal de requisitos manda
 *   solo `p_requisitos`.
 * - p_apoderados NULL = no tocar; cualquier array (incl. []) = sincronizar
 *   (borrar + reinsertar, neto idéntico al `sync()` de Eloquent con un tipo por
 *   apoderado). firstOrCreate por (nombres, apellidos) dentro de la parroquia.
 * - p_requisitos = updateExistingPivot: solo actualiza filas que ya existen en
 *   confirmando_requisito (las crea asignarRutaSacramental).
 * - p_sacramento_faltante_id: si viene, delega en fn_asignar_ruta_sacramental
 *   (cascada bautismo→comunión→confirmación + requisitos acumulados).
 *
 * Devuelve el id del confirmando; el frontend re-lee el detalle con PostgREST.
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
        CREATE OR REPLACE FUNCTION public.fn_guardar_confirmando(
            p_id                     bigint,
            p_datos                  jsonb,
            p_sacramento_faltante_id bigint  DEFAULT NULL,
            p_apoderados             jsonb   DEFAULT NULL,
            p_requisitos             jsonb   DEFAULT NULL
        ) RETURNS bigint
        LANGUAGE plpgsql
        SECURITY INVOKER
        AS $$
        DECLARE
            _id          bigint := p_id;
            _parroquia   bigint := public.app_current_parroquia_id();
            _ap          jsonb;
            _ap_id       bigint;
            _req         jsonb;
        BEGIN
            -- Alta/edición de confirmando son acción de privilegiado (misma regla
            -- que confirmandos_insert / _update). Cortamos con un mensaje claro.
            IF NOT public.app_is_privileged() THEN
                RAISE EXCEPTION 'No autorizado para gestionar confirmandos'
                    USING ERRCODE = 'insufficient_privilege';
            END IF;

            -- ── 1. Confirmando ───────────────────────────────────────────────
            IF _id IS NULL THEN
                -- trg_set_parroquia_id fija parroquia_id desde el claim.
                INSERT INTO public.confirmandos
                    (nombres, apellidos, celular, genero, fecha_nacimiento, grupo_id, estado)
                VALUES (
                    p_datos->>'nombres',
                    p_datos->>'apellidos',
                    NULLIF(p_datos->>'celular', ''),
                    NULLIF(p_datos->>'genero', ''),
                    NULLIF(p_datos->>'fecha_nacimiento', '')::date,
                    NULLIF(p_datos->>'grupo_id', '')::bigint,
                    COALESCE(NULLIF(p_datos->>'estado', ''), 'en_preparacion')
                )
                RETURNING id INTO _id;
            ELSE
                -- Edición parcial: solo las claves presentes en p_datos.
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
                    grupo_id = CASE WHEN jsonb_exists(p_datos, 'grupo_id')
                                    THEN NULLIF(p_datos->>'grupo_id', '')::bigint ELSE grupo_id END,
                    estado = CASE WHEN jsonb_exists(p_datos, 'estado') AND p_datos->>'estado' IS NOT NULL
                                  THEN p_datos->>'estado' ELSE estado END
                WHERE id = _id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Confirmando % no encontrado', _id USING ERRCODE = 'no_data_found';
                END IF;
            END IF;

            -- ── 2. Ruta sacramental (cascada) ────────────────────────────────
            IF p_sacramento_faltante_id IS NOT NULL THEN
                PERFORM public.fn_asignar_ruta_sacramental(_id, p_sacramento_faltante_id);
            END IF;

            -- ── 3. Apoderados (sync) ─────────────────────────────────────────
            -- NULL = no tocar. Cualquier array (incl. []) reemplaza el pivote por
            -- completo: neto idéntico al sync() de Eloquent (keyed por apoderado_id,
            -- un tipo por apoderado). Se pierden created_at/id del pivote, que nadie lee.
            IF p_apoderados IS NOT NULL THEN
                DELETE FROM public.confirmando_apoderado WHERE confirmando_id = _id;

                FOR _ap IN SELECT * FROM jsonb_array_elements(p_apoderados)
                LOOP
                    -- firstOrCreate por nombre completo (la RLS ya acota a la parroquia).
                    SELECT id INTO _ap_id
                      FROM public.apoderados
                     WHERE nombres = _ap->>'nombres'
                       AND apellidos = _ap->>'apellidos'
                     ORDER BY id
                     LIMIT 1;

                    IF _ap_id IS NULL THEN
                        -- apoderados no tiene trigger de parroquia_id: se fija aquí.
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

            -- ── 4. Requisitos (updateExistingPivot) ──────────────────────────
            IF p_requisitos IS NOT NULL THEN
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
        $$;

        -- Permisos de tabla que faltaban (staging revoca los GRANT ALL por defecto
        -- de Supabase; hasta ahora estas 3 tablas solo se escribían vía Laravel).
        -- La RLS (*_insert / _update / _delete con app_is_privileged) sigue gateando.
        GRANT INSERT, UPDATE, DELETE ON public.confirmandos,
                                        public.apoderados,
                                        public.confirmando_apoderado
            TO authenticated;
        GRANT USAGE, SELECT ON SEQUENCE
            public.confirmandos_id_seq,
            public.apoderados_id_seq,
            public.confirmando_apoderado_id_seq
            TO authenticated;

        REVOKE ALL ON FUNCTION public.fn_guardar_confirmando(bigint, jsonb, bigint, jsonb, jsonb) FROM public;
        GRANT EXECUTE ON FUNCTION public.fn_guardar_confirmando(bigint, jsonb, bigint, jsonb, jsonb) TO authenticated;

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.fn_guardar_confirmando(bigint, jsonb, bigint, jsonb, jsonb);
            REVOKE INSERT, UPDATE, DELETE ON public.confirmandos, public.apoderados,
                public.confirmando_apoderado FROM authenticated;
            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
