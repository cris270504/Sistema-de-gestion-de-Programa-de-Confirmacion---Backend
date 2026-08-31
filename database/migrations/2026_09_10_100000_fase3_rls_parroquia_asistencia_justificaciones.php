<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3 — parte 2: gate de parroquia para `asistencia` y `justificaciones`.
 *
 * Su RLS actual (2026_08_11 / 2026_08_28) es SOLO por-grupo: un privilegiado
 * (coordinador/super-admin) de la parroquia A vería registros de la B si lee
 * estas tablas directo por PostgREST. Antes de exponerlas (GRANT SELECT a
 * `authenticated`) hace falta acotarlas por parroquia.
 *
 * Se añade una política RESTRICTIVE (se combina con AND sobre las permisivas de
 * alcance-por-grupo, sin tocarlas):
 * - `asistencia`: pertenece a una `reunion`, que ya está acotada por parroquia
 *   (reunions_parroquia). Si la reunión es visible, la asistencia está en scope.
 * - `justificaciones`: cuelga de una `asistencia`, ya acotada por lo de arriba.
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
            DROP POLICY IF EXISTS asistencia_parroquia ON public.asistencia;
            CREATE POLICY asistencia_parroquia ON public.asistencia AS RESTRICTIVE FOR ALL
                USING (reunion_id IN (SELECT id FROM public.reunions))
                WITH CHECK (reunion_id IN (SELECT id FROM public.reunions));

            DROP POLICY IF EXISTS justificaciones_parroquia ON public.justificaciones;
            CREATE POLICY justificaciones_parroquia ON public.justificaciones AS RESTRICTIVE FOR ALL
                USING (asistencia_id IN (SELECT id FROM public.asistencia))
                WITH CHECK (asistencia_id IN (SELECT id FROM public.asistencia));

            GRANT SELECT ON public.asistencia TO authenticated;
            GRANT SELECT ON public.justificaciones TO authenticated;

            NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            REVOKE SELECT ON public.asistencia FROM authenticated;
            REVOKE SELECT ON public.justificaciones FROM authenticated;
            DROP POLICY IF EXISTS asistencia_parroquia ON public.asistencia;
            DROP POLICY IF EXISTS justificaciones_parroquia ON public.justificaciones;
            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
