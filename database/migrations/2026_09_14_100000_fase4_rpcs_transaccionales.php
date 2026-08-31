<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 — parte 2: escrituras transaccionales como funciones RPC (plpgsql,
 * SECURITY INVOKER → la RLS de cada tabla aplica igual que con Laravel).
 *
 * - fn_guardar_asistencias      ← AsistenciaController::store (upsert masivo)
 * - fn_asignar_ruta_sacramental ← ConfirmandoController::asignarRutaSacramental
 * - fn_justificacion_acuerdo / _completar / _rechazar ← JustificacionController
 *
 * El frontend las llama con supabase.rpc(...). La invalidación de caché del
 * dashboard de Laravel desaparece (las vistas se recalculan on-read).
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
        -- ── 0. Índice único que el ORM daba por hecho (updateOrCreate) ──────
        DELETE FROM public.justificaciones j
         WHERE EXISTS (SELECT 1 FROM public.justificaciones j2
                        WHERE j2.asistencia_id = j.asistencia_id AND j2.id > j.id);
        CREATE UNIQUE INDEX IF NOT EXISTS justificaciones_asistencia_id_uq
            ON public.justificaciones (asistencia_id);

        -- ── 1. Asistencia: upsert masivo de una reunión ─────────────────────
        CREATE OR REPLACE FUNCTION public.fn_guardar_asistencias(
            p_reunion_id bigint,
            p_filas      jsonb
        ) RETURNS jsonb
        LANGUAGE plpgsql
        SECURITY INVOKER
        AS $$
        DECLARE
            _rec       record;
            _n_upd int := 0;
            _n_ins int := 0;
        BEGIN
            -- La reunión debe ser visible para el usuario (RLS). 404 claro en vez de FK 500.
            IF NOT EXISTS (SELECT 1 FROM public.reunions WHERE id = p_reunion_id) THEN
                RAISE EXCEPTION 'Reunión % no encontrada', p_reunion_id USING ERRCODE = 'no_data_found';
            END IF;

            FOR _rec IN
                SELECT * FROM jsonb_to_recordset(p_filas)
                    AS x(asistente_id bigint, asistente_type text, estado text, nota text)
            LOOP
                UPDATE public.asistencia
                   SET estado = _rec.estado, nota = _rec.nota
                 WHERE reunion_id = p_reunion_id
                   AND asistente_id = _rec.asistente_id
                   AND asistente_type = _rec.asistente_type;

                IF FOUND THEN
                    _n_upd := _n_upd + 1;
                ELSE
                    INSERT INTO public.asistencia
                        (reunion_id, asistente_id, asistente_type, estado, nota, created_at, updated_at)
                    VALUES
                        (p_reunion_id, _rec.asistente_id, _rec.asistente_type, _rec.estado, _rec.nota, now(), now());
                    _n_ins := _n_ins + 1;
                END IF;
            END LOOP;

            RETURN jsonb_build_object('actualizadas', _n_upd, 'creadas', _n_ins);
        END;
        $$;

        -- ── 2. Ruta sacramental en cascada ─────────────────────────────────
        CREATE OR REPLACE FUNCTION public.fn_asignar_ruta_sacramental(
            p_confirmando_id       bigint,
            p_sacramento_faltante_id bigint
        ) RETURNS void
        LANGUAGE plpgsql
        SECURITY INVOKER
        AS $$
        DECLARE
            _bautismo    bigint;
            _comunion    bigint;
            _confirmacion bigint;
            _sac_ids     bigint[];
            _req_ids     bigint[];
        BEGIN
            IF p_sacramento_faltante_id IS NULL THEN
                RETURN;
            END IF;

            SELECT id INTO _bautismo     FROM public.sacramentos WHERE clave = 'bautismo'     OR nombre = 'Bautismo'         ORDER BY (clave = 'bautismo') DESC LIMIT 1;
            SELECT id INTO _comunion     FROM public.sacramentos WHERE clave = 'comunion'     OR nombre = 'Primera Comunión' ORDER BY (clave = 'comunion') DESC LIMIT 1;
            SELECT id INTO _confirmacion FROM public.sacramentos WHERE clave = 'confirmacion' OR nombre = 'Confirmación'      ORDER BY (clave = 'confirmacion') DESC LIMIT 1;

            IF _bautismo IS NULL OR _comunion IS NULL OR _confirmacion IS NULL THEN
                RETURN;
            END IF;

            -- Estado por sacramento según cuál falta (cascada).
            IF p_sacramento_faltante_id = _bautismo THEN
                INSERT INTO public.confirmando_sacramento (confirmando_id, sacramento_id, estado) VALUES
                    (p_confirmando_id, _bautismo, 'pendiente'),
                    (p_confirmando_id, _comunion, 'pendiente'),
                    (p_confirmando_id, _confirmacion, 'pendiente')
                ON CONFLICT (confirmando_id, sacramento_id) DO UPDATE SET estado = EXCLUDED.estado;
                _sac_ids := ARRAY[_bautismo, _comunion, _confirmacion];
            ELSIF p_sacramento_faltante_id = _comunion THEN
                INSERT INTO public.confirmando_sacramento (confirmando_id, sacramento_id, estado) VALUES
                    (p_confirmando_id, _bautismo, 'recibido'),
                    (p_confirmando_id, _comunion, 'pendiente'),
                    (p_confirmando_id, _confirmacion, 'pendiente')
                ON CONFLICT (confirmando_id, sacramento_id) DO UPDATE SET estado = EXCLUDED.estado;
                _sac_ids := ARRAY[_comunion, _confirmacion];
            ELSIF p_sacramento_faltante_id = _confirmacion THEN
                INSERT INTO public.confirmando_sacramento (confirmando_id, sacramento_id, estado) VALUES
                    (p_confirmando_id, _bautismo, 'recibido'),
                    (p_confirmando_id, _comunion, 'recibido'),
                    (p_confirmando_id, _confirmacion, 'pendiente')
                ON CONFLICT (confirmando_id, sacramento_id) DO UPDATE SET estado = EXCLUDED.estado;
                _sac_ids := ARRAY[_confirmacion];
            ELSE
                RETURN;
            END IF;

            -- Sincroniza los 3 sacramentos de la ruta (borra si alguna vez hubo otros).
            DELETE FROM public.confirmando_sacramento
             WHERE confirmando_id = p_confirmando_id
               AND sacramento_id NOT IN (_bautismo, _comunion, _confirmacion);

            -- Requisitos: los de los sacramentos "pendientes" acumulados.
            SELECT coalesce(array_agg(DISTINCT sr.requisito_id), '{}')
              INTO _req_ids
              FROM public.sacramento_requisito sr
             WHERE sr.sacramento_id = ANY(_sac_ids);

            -- Alta de los nuevos como 'pendiente'; los ya existentes conservan su estado.
            INSERT INTO public.confirmando_requisito (confirmando_id, requisito_id, estado)
            SELECT p_confirmando_id, r, 'pendiente'
              FROM unnest(_req_ids) r
            ON CONFLICT (confirmando_id, requisito_id) DO NOTHING;

            -- Baja de los que ya no corresponden.
            DELETE FROM public.confirmando_requisito
             WHERE confirmando_id = p_confirmando_id
               AND requisito_id <> ALL(_req_ids);
        END;
        $$;

        -- ── 3. Justificaciones ────────────────────────────────────────────
        CREATE OR REPLACE FUNCTION public.fn_justificacion_acuerdo(
            p_asistencia_id bigint,
            p_motivo        text,
            p_descripcion   text,
            p_fecha_acuerdo date
        ) RETURNS void
        LANGUAGE sql
        SECURITY INVOKER
        AS $$
            INSERT INTO public.justificaciones
                (asistencia_id, motivo, descripcion, fecha_acuerdo, estado, created_at, updated_at)
            VALUES
                (p_asistencia_id, p_motivo, p_descripcion, p_fecha_acuerdo, 'pendiente', now(), now())
            ON CONFLICT (asistencia_id) DO UPDATE SET
                motivo = EXCLUDED.motivo,
                descripcion = EXCLUDED.descripcion,
                fecha_acuerdo = EXCLUDED.fecha_acuerdo,
                estado = 'pendiente',
                updated_at = now();
        $$;

        CREATE OR REPLACE FUNCTION public.fn_justificacion_completar(p_asistencia_id bigint)
        RETURNS void
        LANGUAGE plpgsql
        SECURITY INVOKER
        AS $$
        DECLARE _motivo text;
        BEGIN
            UPDATE public.justificaciones SET estado = 'justificado', updated_at = now()
             WHERE asistencia_id = p_asistencia_id
            RETURNING motivo INTO _motivo;

            IF NOT FOUND THEN
                RAISE EXCEPTION 'No hay acuerdo para la asistencia %', p_asistencia_id USING ERRCODE = 'no_data_found';
            END IF;

            UPDATE public.asistencia
               SET estado = 'falta justificada', nota = 'Justificado: ' || coalesce(_motivo, '')
             WHERE id = p_asistencia_id;
        END;
        $$;

        CREATE OR REPLACE FUNCTION public.fn_justificacion_rechazar(p_asistencia_id bigint)
        RETURNS void
        LANGUAGE plpgsql
        SECURITY INVOKER
        AS $$
        BEGIN
            UPDATE public.justificaciones
               SET estado = 'no_cumplido',
                   descripcion = trim(coalesce(descripcion, '') || E'\n\n[NOTA: NO CUMPLIÓ CON LA ACCIÓN PACTADA]'),
                   updated_at = now()
             WHERE asistencia_id = p_asistencia_id;

            IF NOT FOUND THEN
                RAISE EXCEPTION 'No se encontró un acuerdo registrado.' USING ERRCODE = 'no_data_found';
            END IF;

            UPDATE public.asistencia SET estado = 'falta injustificada' WHERE id = p_asistencia_id;
        END;
        $$;

        -- ── Permisos de tabla (las funciones corren como `authenticated`;
        --    la RLS de cada tabla las gatea) ────────────────────────────────
        GRANT INSERT, UPDATE, DELETE ON public.asistencia,
                                        public.justificaciones,
                                        public.confirmando_sacramento,
                                        public.confirmando_requisito
            TO authenticated;
        GRANT USAGE, SELECT ON SEQUENCE
            public.asistencia_id_seq,
            public.justificaciones_id_seq,
            public.confirmando_sacramento_id_seq,
            public.confirmando_requisito_id_seq
            TO authenticated;

        -- ── Permisos de las funciones ────────────────────────────────────
        DO $$
        DECLARE _fn text;
        BEGIN
            FOREACH _fn IN ARRAY ARRAY[
                'fn_guardar_asistencias(bigint, jsonb)',
                'fn_asignar_ruta_sacramental(bigint, bigint)',
                'fn_justificacion_acuerdo(bigint, text, text, date)',
                'fn_justificacion_completar(bigint)',
                'fn_justificacion_rechazar(bigint)'
            ] LOOP
                EXECUTE format('REVOKE ALL ON FUNCTION public.%s FROM public', _fn);
                EXECUTE format('GRANT EXECUTE ON FUNCTION public.%s TO authenticated', _fn);
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
            DROP FUNCTION IF EXISTS public.fn_guardar_asistencias(bigint, jsonb);
            DROP FUNCTION IF EXISTS public.fn_asignar_ruta_sacramental(bigint, bigint);
            DROP FUNCTION IF EXISTS public.fn_justificacion_acuerdo(bigint, text, text, date);
            DROP FUNCTION IF EXISTS public.fn_justificacion_completar(bigint);
            DROP FUNCTION IF EXISTS public.fn_justificacion_rechazar(bigint);
            REVOKE INSERT, UPDATE, DELETE ON public.asistencia, public.justificaciones,
                public.confirmando_sacramento, public.confirmando_requisito FROM authenticated;
            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
