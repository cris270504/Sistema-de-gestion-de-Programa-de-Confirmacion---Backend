<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Integridad de `asistencia` — cierra C1, C2(reunión futura), M3, M4 del stress test.
 *
 * 1) Limpieza de filas huérfanas (confirmando/apoderado borrado; asistente
 *    polimórfico sin FK).
 * 2) UNIQUE (reunion_id, asistente_id, asistente_type) → imposible duplicar por carrera.
 * 3) fn_guardar_asistencias: advisory lock por reunión + INSERT ... ON CONFLICT.
 * 4) Trigger BEFORE INSERT/UPDATE que hace inviolables las reglas (aunque se llame
 *    a PostgREST directo, no solo por el RPC):
 *      - asistente_type dentro del dominio conocido
 *      - el asistente (Confirmando/Apoderado) existe y es visible (RLS ⇒ parroquia)
 *      - la reunión ya empezó (zona horaria de la parroquia)
 *      - no se ABRE asistencia de un Confirmando 'retirado' (editar histórico sí)
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
        -- ── 1. Huérfanas ────────────────────────────────────────────────
        DELETE FROM public.asistencia a
         WHERE a.asistente_type = 'App\Models\Confirmando'
           AND NOT EXISTS (SELECT 1 FROM public.confirmandos c WHERE c.id = a.asistente_id);
        DELETE FROM public.asistencia a
         WHERE a.asistente_type = 'App\Models\Apoderado'
           AND NOT EXISTS (SELECT 1 FROM public.apoderados p WHERE p.id = a.asistente_id);

        -- ── 2. Deduplicar + índice único ───────────────────────────────
        DELETE FROM public.asistencia a
              USING public.asistencia b
         WHERE a.id > b.id
           AND a.reunion_id     = b.reunion_id
           AND a.asistente_id   = b.asistente_id
           AND a.asistente_type = b.asistente_type;

        CREATE UNIQUE INDEX IF NOT EXISTS asistencia_reunion_asistente_uq
            ON public.asistencia (reunion_id, asistente_id, asistente_type);

        -- ── 3. fn_guardar_asistencias: lock + upsert atómico ───────────
        CREATE OR REPLACE FUNCTION public.fn_guardar_asistencias(p_reunion_id bigint, p_filas jsonb)
        RETURNS jsonb
        LANGUAGE plpgsql
        AS $fn$
        DECLARE
            _rec        record;
            _was_insert boolean;
            _n_upd      int := 0;
            _n_ins      int := 0;
        BEGIN
            PERFORM pg_advisory_xact_lock(hashtext('asistencia:' || p_reunion_id));

            IF NOT EXISTS (SELECT 1 FROM public.reunions r WHERE r.id = p_reunion_id) THEN
                RAISE EXCEPTION 'Reunión % no encontrada', p_reunion_id USING ERRCODE = 'no_data_found';
            END IF;

            FOR _rec IN
                SELECT * FROM jsonb_to_recordset(p_filas)
                    AS x(asistente_id bigint, asistente_type text, estado text, nota text)
            LOOP
                WITH up AS (
                    INSERT INTO public.asistencia
                        (reunion_id, asistente_id, asistente_type, estado, nota, created_at, updated_at)
                    VALUES
                        (p_reunion_id, _rec.asistente_id, _rec.asistente_type, _rec.estado, _rec.nota, now(), now())
                    ON CONFLICT (reunion_id, asistente_id, asistente_type)
                    DO UPDATE SET estado = EXCLUDED.estado, nota = EXCLUDED.nota, updated_at = now()
                    RETURNING (xmax = 0) AS ins
                )
                SELECT ins INTO _was_insert FROM up;

                IF _was_insert THEN _n_ins := _n_ins + 1; ELSE _n_upd := _n_upd + 1; END IF;
            END LOOP;

            RETURN jsonb_build_object('actualizadas', _n_upd, 'creadas', _n_ins);
        END;
        $fn$;

        -- ── 4. Trigger de reglas inviolables ──────────────────────────
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

        DROP TRIGGER IF EXISTS trg_asistencia_valida ON public.asistencia;
        CREATE TRIGGER trg_asistencia_valida
            BEFORE INSERT OR UPDATE ON public.asistencia
            FOR EACH ROW EXECUTE FUNCTION public.asistencia_valida();

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
        DROP TRIGGER IF EXISTS trg_asistencia_valida ON public.asistencia;
        DROP FUNCTION IF EXISTS public.asistencia_valida();
        DROP INDEX IF EXISTS public.asistencia_reunion_asistente_uq;
        NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
