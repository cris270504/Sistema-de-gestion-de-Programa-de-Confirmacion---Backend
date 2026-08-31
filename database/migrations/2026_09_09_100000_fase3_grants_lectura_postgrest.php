<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3 de la migración a Supabase — LECTURAS por PostgREST.
 *
 * El proyecto Supabase tiene "Automatically expose new tables" OFF, así que las
 * tablas creadas por las migraciones de Laravel NO están en el Data API. Acá se
 * da `GRANT SELECT` explícito a `authenticated` en las tablas de dominio que el
 * frontend va a leer directo con `supabase.from(...)`.
 *
 * Seguridad: cada una ya tiene RLS por claims (Fases 0/1/2) → el grant expone la
 * tabla pero la RLS filtra las filas (parroquia + alcance por grupo).
 *
 * NO se incluyen todavía (se quedan en Laravel hasta tener su gate de parroquia):
 * - `asistencia` y `justificaciones`: su RLS es solo por-grupo; un privilegiado
 *   de la parroquia A vería registros de la B vía PostgREST. Necesitan política
 *   RESTRICTIVE de parroquia antes de exponerlas.
 * - `roles`/`permissions` (Spatie): gestión admin, se queda en Laravel.
 *
 * Solo pgsql.
 */
return new class extends Migration
{
    private array $tablas = [
        // raíz (parroquia_id directo, RESTRICTIVE _parroquia vigente)
        'confirmandos', 'grupos', 'reunions', 'sacramentos', 'requisitos',
        'tipo_apoderados', 'parroquia_configuraciones', 'users',
        // alcance por grupo / vía padre
        'apoderados', 'catequista_grupo',
        // pivotes (alcance transitivo: x_id IN (SELECT id FROM <padre acotado>))
        'confirmando_apoderado', 'confirmando_sacramento', 'confirmando_requisito',
        'sacramento_requisito', 'reunion_user',
        // tenant
        'parroquias',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tablas as $t) {
            DB::unprepared("GRANT SELECT ON public.{$t} TO authenticated;");
        }

        // PostgREST cachea el esquema; hay que avisarle que recargue.
        DB::unprepared("NOTIFY pgrst, 'reload schema';");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tablas as $t) {
            DB::unprepared("REVOKE SELECT ON public.{$t} FROM authenticated;");
        }

        DB::unprepared("NOTIFY pgrst, 'reload schema';");
    }
};
