<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 — parte 1: escrituras de CRUD simple (catálogos + grupos + reuniones)
 * por PostgREST.
 *
 * - GRANT INSERT/UPDATE/DELETE a `authenticated` en las tablas de catálogo. La
 *   RLS ya tiene políticas de escritura (`*_write` / `*_insert` etc. con
 *   `app_is_privileged()`), así que solo un privilegiado escribe.
 * - Trigger `set_parroquia_id_desde_claim`: en INSERT, si `parroquia_id` viene
 *   NULL lo fija desde el claim del JWT (`app_current_parroquia_id()`). Reemplaza
 *   el hook `BelongsToParroquia::creating` de Eloquent para las inserciones que
 *   ya no pasan por Laravel. La RESTRICTIVE `*_parroquia` (WITH CHECK) exige que
 *   quede seteado.
 * - CHECKs defensivos (celular 9 dígitos, color hex). Los `tipo`/`procedencia`
 *   ya tienen CHECK contra el set por defecto; si una parroquia los personaliza
 *   habrá que pasarlos a trigger contra `parroquia_configuraciones`.
 *
 * Solo pgsql.
 */
return new class extends Migration
{
    private array $tablas = ['sacramentos', 'requisitos', 'tipo_apoderados', 'grupos', 'reunions'];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // ── Trigger genérico: fija parroquia_id desde el claim en INSERT ──────
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.set_parroquia_id_desde_claim()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.parroquia_id IS NULL THEN
                    NEW.parroquia_id := public.app_current_parroquia_id();
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);

        foreach (array_merge($this->tablas, ['confirmandos', 'apoderados']) as $t) {
            // apoderados no tiene parroquia_id → se salta
            if ($t === 'apoderados') {
                continue;
            }
            DB::unprepared("
                DROP TRIGGER IF EXISTS trg_set_parroquia_id ON public.{$t};
                CREATE TRIGGER trg_set_parroquia_id
                    BEFORE INSERT ON public.{$t}
                    FOR EACH ROW EXECUTE FUNCTION public.set_parroquia_id_desde_claim();
            ");
        }

        // ── Grants de escritura (+ USAGE de la secuencia del id, requerido
        //    para INSERT en PKs serial) ────────────────────────────────────────
        foreach ($this->tablas as $t) {
            DB::unprepared("
                GRANT INSERT, UPDATE, DELETE ON public.{$t} TO authenticated;
                GRANT USAGE, SELECT ON SEQUENCE public.{$t}_id_seq TO authenticated;
            ");
        }

        // ── created_at / updated_at: los ponía Eloquent; ahora la BD ─────────
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.touch_updated_at()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN NEW.updated_at := now(); RETURN NEW; END;
            $$;
        SQL);

        foreach (array_merge($this->tablas, ['confirmandos']) as $t) {
            DB::unprepared("
                ALTER TABLE public.{$t} ALTER COLUMN created_at SET DEFAULT now();
                ALTER TABLE public.{$t} ALTER COLUMN updated_at SET DEFAULT now();
                DROP TRIGGER IF EXISTS trg_touch_updated_at ON public.{$t};
                CREATE TRIGGER trg_touch_updated_at
                    BEFORE UPDATE ON public.{$t}
                    FOR EACH ROW EXECUTE FUNCTION public.touch_updated_at();
            ");
        }

        // ── CHECKs defensivos ────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.confirmandos
                DROP CONSTRAINT IF EXISTS confirmandos_celular_check,
                ADD  CONSTRAINT confirmandos_celular_check
                     CHECK (celular IS NULL OR celular ~ '^[0-9]{9}$');

            ALTER TABLE public.grupos
                DROP CONSTRAINT IF EXISTS grupos_color_check,
                ADD  CONSTRAINT grupos_color_check
                     CHECK (color ~ '^#[0-9A-Fa-f]{3,8}$');

            NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_merge($this->tablas, ['confirmandos']) as $t) {
            DB::unprepared("
                DROP TRIGGER IF EXISTS trg_set_parroquia_id ON public.{$t};
                DROP TRIGGER IF EXISTS trg_touch_updated_at ON public.{$t};
            ");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS public.set_parroquia_id_desde_claim(); DROP FUNCTION IF EXISTS public.touch_updated_at();');

        foreach ($this->tablas as $t) {
            DB::unprepared("
                REVOKE INSERT, UPDATE, DELETE ON public.{$t} FROM authenticated;
                REVOKE USAGE, SELECT ON SEQUENCE public.{$t}_id_seq FROM authenticated;
            ");
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.confirmandos DROP CONSTRAINT IF EXISTS confirmandos_celular_check;
            ALTER TABLE public.grupos DROP CONSTRAINT IF EXISTS grupos_color_check;
            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
