<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * No se puede registrar asistencia de una reunión que todavía no empezó.
 *
 * Caso real: una catequista marcó en la columna de una reunión futura lo que
 * correspondía a una reunión ya pasada. fn_guardar_asistencias ahora rechaza si
 * `reunions.fecha` es posterior a "ahora" en la zona horaria de la parroquia.
 * Vale para todos los roles (nadie asistió al futuro); la corrección de
 * asistencias pasadas sigue igual.
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
        CREATE OR REPLACE FUNCTION public.fn_guardar_asistencias(
            p_reunion_id bigint,
            p_filas      jsonb
        ) RETURNS jsonb
        LANGUAGE plpgsql
        SECURITY INVOKER
        AS $$
        DECLARE
            _rec    record;
            _n_upd  int := 0;
            _n_ins  int := 0;
            _fecha  timestamp;
            _tz     text;
        BEGIN
            -- La reunión debe ser visible para el usuario (RLS). 404 claro en vez de FK 500.
            SELECT r.fecha INTO _fecha FROM public.reunions r WHERE r.id = p_reunion_id;
            IF _fecha IS NULL THEN
                RAISE EXCEPTION 'Reunión % no encontrada', p_reunion_id USING ERRCODE = 'no_data_found';
            END IF;

            _tz := coalesce(
                (SELECT zona_horaria FROM public.parroquias WHERE id = public.app_current_parroquia_id()),
                'America/Lima'
            );

            IF _fecha > (now() AT TIME ZONE _tz) THEN
                RAISE EXCEPTION 'La reunión del % todavía no empezó; aún no se puede registrar su asistencia',
                    to_char(_fecha, 'DD/MM/YYYY HH24:MI')
                    USING ERRCODE = 'check_violation';
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

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
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

        NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
