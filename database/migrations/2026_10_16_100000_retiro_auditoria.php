<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de retiro — stress test B1 (y apoyo a M9).
 *
 * `confirmandos.fecha_retiro` / `motivo_retiro`: hasta ahora "retirar" era solo
 * `estado = 'retirado'`, sin rastro de cuándo ni por qué, y el reingreso no
 * limpiaba nada. Un trigger BEFORE UPDATE OF estado mantiene `fecha_retiro`
 * automáticamente (la marca al retirar, la limpia al reingresar).
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
        ALTER TABLE public.confirmandos
            ADD COLUMN IF NOT EXISTS fecha_retiro  timestamp,
            ADD COLUMN IF NOT EXISTS motivo_retiro text;

        -- Backfill: los ya retirados sin fecha → updated_at como aproximación.
        UPDATE public.confirmandos
           SET fecha_retiro = updated_at
         WHERE estado = 'retirado' AND fecha_retiro IS NULL;

        CREATE OR REPLACE FUNCTION public.confirmando_marca_retiro()
        RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            IF NEW.estado = 'retirado' AND OLD.estado IS DISTINCT FROM 'retirado' THEN
                NEW.fecha_retiro := now();
            ELSIF NEW.estado IS DISTINCT FROM 'retirado' AND OLD.estado = 'retirado' THEN
                NEW.fecha_retiro  := NULL;
                NEW.motivo_retiro := NULL;
            END IF;
            RETURN NEW;
        END;
        $fn$;

        DROP TRIGGER IF EXISTS trg_confirmando_marca_retiro ON public.confirmandos;
        CREATE TRIGGER trg_confirmando_marca_retiro
            BEFORE UPDATE OF estado ON public.confirmandos
            FOR EACH ROW
            WHEN (NEW.estado IS DISTINCT FROM OLD.estado)
            EXECUTE FUNCTION public.confirmando_marca_retiro();

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
        DROP TRIGGER IF EXISTS trg_confirmando_marca_retiro ON public.confirmandos;
        DROP FUNCTION IF EXISTS public.confirmando_marca_retiro();
        ALTER TABLE public.confirmandos
            DROP COLUMN IF EXISTS fecha_retiro,
            DROP COLUMN IF EXISTS motivo_retiro;
        NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
