<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Borrados seguros — stress test A5 y M1.
 *
 * A5: eliminar un confirmando era un hard-delete. `asistencia`/`justificaciones`
 *     no tienen FK al confirmando (asistente polimórfico) → filas huérfanas +
 *     pérdida irreversible del historial. Ahora un trigger BEFORE DELETE lo
 *     bloquea si tiene asistencia; el camino correcto es "Retirar".
 *
 * M1: eliminar un grupo dejaba N confirmandos "sin grupo" (SET NULL) y los
 *     catequistas sin asignación (CASCADE), sin aviso. Ahora se bloquea si el
 *     grupo tiene confirmandos: hay que reasignarlos primero.
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
        CREATE OR REPLACE FUNCTION public.confirmando_bloquea_borrado()
        RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            IF EXISTS (SELECT 1 FROM public.asistencia
                        WHERE asistente_type = 'App\Models\Confirmando'
                          AND asistente_id = OLD.id) THEN
                RAISE EXCEPTION
                    'No se puede eliminar a "%, %": tiene historial de asistencia. Usa "Retirar del programa".',
                    OLD.apellidos, OLD.nombres
                    USING ERRCODE = 'foreign_key_violation';
            END IF;
            RETURN OLD;
        END;
        $fn$;

        DROP TRIGGER IF EXISTS trg_confirmando_bloquea_borrado ON public.confirmandos;
        CREATE TRIGGER trg_confirmando_bloquea_borrado
            BEFORE DELETE ON public.confirmandos
            FOR EACH ROW EXECUTE FUNCTION public.confirmando_bloquea_borrado();

        CREATE OR REPLACE FUNCTION public.grupo_bloquea_borrado()
        RETURNS trigger LANGUAGE plpgsql AS $fn$
        DECLARE _n int;
        BEGIN
            SELECT count(*) INTO _n FROM public.confirmandos WHERE grupo_id = OLD.id;
            IF _n > 0 THEN
                RAISE EXCEPTION
                    'No se puede eliminar el grupo "%": tiene % confirmando(s) asignado(s). Reasígnalos o quítalos del grupo primero.',
                    OLD.nombre, _n
                    USING ERRCODE = 'foreign_key_violation';
            END IF;
            RETURN OLD;
        END;
        $fn$;

        DROP TRIGGER IF EXISTS trg_grupo_bloquea_borrado ON public.grupos;
        CREATE TRIGGER trg_grupo_bloquea_borrado
            BEFORE DELETE ON public.grupos
            FOR EACH ROW EXECUTE FUNCTION public.grupo_bloquea_borrado();
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
        DROP TRIGGER IF EXISTS trg_confirmando_bloquea_borrado ON public.confirmandos;
        DROP TRIGGER IF EXISTS trg_grupo_bloquea_borrado ON public.grupos;
        DROP FUNCTION IF EXISTS public.confirmando_bloquea_borrado();
        DROP FUNCTION IF EXISTS public.grupo_bloquea_borrado();
        SQL);
    }
};
