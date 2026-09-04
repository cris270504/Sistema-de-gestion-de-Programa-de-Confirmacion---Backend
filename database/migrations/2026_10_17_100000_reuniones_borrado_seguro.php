<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stress test ronda 2 — R2-1 y R2-7.
 *
 * R2-1: borrar una reunión hacía CASCADE de toda su `asistencia` + `justificaciones`
 *       sin aviso ni bloqueo (paralelo a A5/M1). Ahora un trigger BEFORE DELETE lo
 *       bloquea si la reunión tiene asistencia registrada.
 *
 * R2-7: limpieza puntual de filas huérfanas legacy — `asistencia` de tipo
 *       `App\Models\User` cuyo usuario ya no existe (borrados antes de que
 *       `fn_admin_eliminar_usuario` limpiara), y `apoderados` sin ningún confirmando.
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
        -- ── R2-7: huérfanas legacy ─────────────────────────────────────────
        DELETE FROM public.justificaciones j
         WHERE j.asistencia_id IN (
            SELECT a.id FROM public.asistencia a
             WHERE a.asistente_type = 'App\Models\User'
               AND NOT EXISTS (SELECT 1 FROM public.users u WHERE u.id = a.asistente_id)
         );
        DELETE FROM public.asistencia a
         WHERE a.asistente_type = 'App\Models\User'
           AND NOT EXISTS (SELECT 1 FROM public.users u WHERE u.id = a.asistente_id);

        DELETE FROM public.apoderados ap
         WHERE NOT EXISTS (SELECT 1 FROM public.confirmando_apoderado ca WHERE ca.apoderado_id = ap.id);

        -- ── R2-1: no borrar una reunión con asistencia registrada ──────────
        CREATE OR REPLACE FUNCTION public.reunion_bloquea_borrado()
        RETURNS trigger LANGUAGE plpgsql AS $fn$
        DECLARE _n int;
        BEGIN
            SELECT count(*) INTO _n FROM public.asistencia WHERE reunion_id = OLD.id;
            IF _n > 0 THEN
                RAISE EXCEPTION
                    'No se puede eliminar la reunión "%" (% de %): tiene % registro(s) de asistencia. Edítala en vez de borrarla.',
                    OLD.nombre_tema, to_char(OLD.fecha, 'DD/MM/YYYY'), OLD.tipo, _n
                    USING ERRCODE = 'foreign_key_violation';
            END IF;
            RETURN OLD;
        END;
        $fn$;

        DROP TRIGGER IF EXISTS trg_reunion_bloquea_borrado ON public.reunions;
        CREATE TRIGGER trg_reunion_bloquea_borrado
            BEFORE DELETE ON public.reunions
            FOR EACH ROW EXECUTE FUNCTION public.reunion_bloquea_borrado();
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
        DROP TRIGGER IF EXISTS trg_reunion_bloquea_borrado ON public.reunions;
        DROP FUNCTION IF EXISTS public.reunion_bloquea_borrado();
        SQL);
    }
};
