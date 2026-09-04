<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stress test ronda 2 — R2-3, R2-5, R2-6, R2-10.
 *
 * - Los 3 RPC de justificación (`_acuerdo`, `_completar`, `_rechazar`) pasan a
 *   plpgsql con: gate explícito (privilegiado o acceso a esa asistencia),
 *   advisory lock por asistencia (R2-6), verificación de que la asistencia sea
 *   una FALTA (R2-3) y `FOUND` en el UPDATE de `asistencia` (R2-6).
 * - `_acuerdo` valida `fecha_acuerdo` (rango sensato) y trunca `motivo`/`descripcion`.
 * - `asistencia_valida` valida también la existencia de `App\Models\User` (R2-10).
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
        -- ── Helper: ¿el usuario puede tocar esta asistencia? ───────────────
        CREATE OR REPLACE FUNCTION public._justif_puede(p_asistencia_id bigint)
        RETURNS boolean LANGUAGE sql STABLE AS $fn$
            SELECT public.app_is_privileged() OR EXISTS (
                SELECT 1 FROM public.asistencia a
                 WHERE a.id = p_asistencia_id
                   AND public.app_can_access_asistente(a.asistente_type::text, a.asistente_id)
            )
        $fn$;

        -- ── fn_justificacion_acuerdo ──────────────────────────────────────
        CREATE OR REPLACE FUNCTION public.fn_justificacion_acuerdo(
            p_asistencia_id bigint, p_motivo text, p_descripcion text, p_fecha_acuerdo date
        ) RETURNS void
        LANGUAGE plpgsql
        AS $fn$
        DECLARE _estado text;
        BEGIN
            PERFORM pg_advisory_xact_lock(hashtext('justif:' || p_asistencia_id));

            IF NOT public._justif_puede(p_asistencia_id) THEN
                RAISE EXCEPTION 'No autorizado para justificar esta asistencia' USING ERRCODE = 'insufficient_privilege';
            END IF;

            SELECT estado INTO _estado FROM public.asistencia WHERE id = p_asistencia_id;
            IF _estado IS NULL THEN
                RAISE EXCEPTION 'Asistencia % no encontrada', p_asistencia_id USING ERRCODE = 'no_data_found';
            END IF;
            IF _estado NOT IN ('falta justificada', 'falta injustificada') THEN
                RAISE EXCEPTION 'Solo se registran acuerdos de justificación sobre faltas (estado actual: %)', _estado
                    USING ERRCODE = 'check_violation';
            END IF;

            IF p_fecha_acuerdo IS NULL
               OR p_fecha_acuerdo < date '2020-01-01'
               OR p_fecha_acuerdo > (current_date + interval '1 year') THEN
                RAISE EXCEPTION 'La fecha del acuerdo no es válida' USING ERRCODE = 'check_violation';
            END IF;

            INSERT INTO public.justificaciones
                (asistencia_id, motivo, descripcion, fecha_acuerdo, estado, created_at, updated_at)
            VALUES
                (p_asistencia_id, left(coalesce(p_motivo, ''), 300), left(coalesce(p_descripcion, ''), 1000),
                 p_fecha_acuerdo, 'pendiente', now(), now())
            ON CONFLICT (asistencia_id) DO UPDATE SET
                motivo        = EXCLUDED.motivo,
                descripcion   = EXCLUDED.descripcion,
                fecha_acuerdo = EXCLUDED.fecha_acuerdo,
                estado        = 'pendiente',
                updated_at    = now();
        END;
        $fn$;

        -- ── fn_justificacion_completar ───────────────────────────────────
        CREATE OR REPLACE FUNCTION public.fn_justificacion_completar(p_asistencia_id bigint)
        RETURNS void
        LANGUAGE plpgsql
        AS $fn$
        DECLARE _motivo text; _estado text;
        BEGIN
            PERFORM pg_advisory_xact_lock(hashtext('justif:' || p_asistencia_id));

            IF NOT public._justif_puede(p_asistencia_id) THEN
                RAISE EXCEPTION 'No autorizado para gestionar esta justificación' USING ERRCODE = 'insufficient_privilege';
            END IF;

            SELECT estado INTO _estado FROM public.asistencia WHERE id = p_asistencia_id;
            IF _estado NOT IN ('falta justificada', 'falta injustificada') THEN
                RAISE EXCEPTION 'La asistencia ya no es una falta (estado: %)', _estado USING ERRCODE = 'check_violation';
            END IF;

            UPDATE public.justificaciones SET estado = 'justificado', updated_at = now()
             WHERE asistencia_id = p_asistencia_id
            RETURNING motivo INTO _motivo;
            IF NOT FOUND THEN
                RAISE EXCEPTION 'No hay acuerdo para la asistencia %', p_asistencia_id USING ERRCODE = 'no_data_found';
            END IF;

            UPDATE public.asistencia
               SET estado = 'falta justificada', nota = 'Justificado: ' || coalesce(_motivo, '')
             WHERE id = p_asistencia_id;
            IF NOT FOUND THEN
                RAISE EXCEPTION 'No se pudo actualizar la asistencia %', p_asistencia_id USING ERRCODE = 'no_data_found';
            END IF;
        END;
        $fn$;

        -- ── fn_justificacion_rechazar ────────────────────────────────────
        CREATE OR REPLACE FUNCTION public.fn_justificacion_rechazar(p_asistencia_id bigint)
        RETURNS void
        LANGUAGE plpgsql
        AS $fn$
        DECLARE _estado text;
        BEGIN
            PERFORM pg_advisory_xact_lock(hashtext('justif:' || p_asistencia_id));

            IF NOT public._justif_puede(p_asistencia_id) THEN
                RAISE EXCEPTION 'No autorizado para gestionar esta justificación' USING ERRCODE = 'insufficient_privilege';
            END IF;

            SELECT estado INTO _estado FROM public.asistencia WHERE id = p_asistencia_id;
            IF _estado NOT IN ('falta justificada', 'falta injustificada') THEN
                RAISE EXCEPTION 'La asistencia ya no es una falta (estado: %)', _estado USING ERRCODE = 'check_violation';
            END IF;

            UPDATE public.justificaciones
               SET estado = 'no_cumplido',
                   descripcion = left(trim(coalesce(descripcion, '') || E'\n\n[NOTA: NO CUMPLIÓ CON LA ACCIÓN PACTADA]'), 1000),
                   updated_at = now()
             WHERE asistencia_id = p_asistencia_id;
            IF NOT FOUND THEN
                RAISE EXCEPTION 'No se encontró un acuerdo registrado.' USING ERRCODE = 'no_data_found';
            END IF;

            UPDATE public.asistencia SET estado = 'falta injustificada' WHERE id = p_asistencia_id;
            IF NOT FOUND THEN
                RAISE EXCEPTION 'No se pudo actualizar la asistencia %', p_asistencia_id USING ERRCODE = 'no_data_found';
            END IF;
        END;
        $fn$;

        REVOKE ALL ON FUNCTION public._justif_puede(bigint) FROM public;
        GRANT EXECUTE ON FUNCTION public._justif_puede(bigint) TO authenticated;

        -- ── asistencia_valida: validar existencia de App\Models\User (R2-10) ──
        CREATE OR REPLACE FUNCTION public.asistencia_valida()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $fn$
        DECLARE
            _fecha timestamp;
            _tz    text;
        BEGIN
            IF NEW.asistente_type NOT IN
               ('App\Models\Confirmando', 'App\Models\Apoderado', 'App\Models\User') THEN
                RAISE EXCEPTION 'Tipo de asistente no válido: %', NEW.asistente_type
                    USING ERRCODE = 'check_violation';
            END IF;

            IF NEW.asistente_type = 'App\Models\Confirmando'
               AND NOT EXISTS (SELECT 1 FROM public.confirmandos WHERE id = NEW.asistente_id) THEN
                RAISE EXCEPTION 'El confirmando #% no existe en esta parroquia', NEW.asistente_id
                    USING ERRCODE = 'foreign_key_violation';
            END IF;
            IF NEW.asistente_type = 'App\Models\Apoderado'
               AND NOT EXISTS (SELECT 1 FROM public.apoderados WHERE id = NEW.asistente_id) THEN
                RAISE EXCEPTION 'El apoderado #% no existe en esta parroquia', NEW.asistente_id
                    USING ERRCODE = 'foreign_key_violation';
            END IF;
            IF NEW.asistente_type = 'App\Models\User'
               AND NOT EXISTS (SELECT 1 FROM public.users WHERE id = NEW.asistente_id) THEN
                RAISE EXCEPTION 'El usuario #% no existe en esta parroquia', NEW.asistente_id
                    USING ERRCODE = 'foreign_key_violation';
            END IF;

            SELECT r.fecha INTO _fecha FROM public.reunions r WHERE r.id = NEW.reunion_id;
            _tz := coalesce(
                (SELECT zona_horaria FROM public.parroquias WHERE id = public.app_current_parroquia_id()),
                'America/Lima'
            );
            IF _fecha IS NOT NULL AND _fecha > (now() AT TIME ZONE _tz) THEN
                RAISE EXCEPTION 'La reunión del % todavía no empezó; aún no se puede registrar su asistencia',
                    to_char(_fecha, 'DD/MM/YYYY HH24:MI') USING ERRCODE = 'check_violation';
            END IF;

            IF TG_OP = 'INSERT'
               AND NEW.asistente_type = 'App\Models\Confirmando'
               AND EXISTS (SELECT 1 FROM public.confirmandos
                            WHERE id = NEW.asistente_id AND estado = 'retirado') THEN
                RAISE EXCEPTION 'El confirmando está retirado del programa; no se le registra asistencia'
                    USING ERRCODE = 'check_violation';
            END IF;

            RETURN NEW;
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
        DROP FUNCTION IF EXISTS public._justif_puede(bigint);
        NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
