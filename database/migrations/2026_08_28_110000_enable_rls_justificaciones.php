<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RLS para `justificaciones`. Hereda el alcance de la `asistencia` que corrige:
 * un catequista solo ve/gestiona las justificaciones de faltas de confirmandos de
 * sus grupos. Reutiliza las funciones auxiliares creadas en
 * 2026_08_11_120000_enable_row_level_security (app_is_privileged,
 * app_can_access_asistente).
 *
 * Solo aplica sobre pgsql (los tests corren en sqlite y el filtro por grupo del
 * controlador ya cubre ese caso).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE justificaciones ENABLE ROW LEVEL SECURITY;
            ALTER TABLE justificaciones FORCE ROW LEVEL SECURITY;

            CREATE POLICY justificaciones_select ON justificaciones FOR SELECT
                USING (
                    app_is_privileged()
                    OR EXISTS (
                        SELECT 1 FROM asistencia a
                        WHERE a.id = justificaciones.asistencia_id
                          AND app_can_access_asistente(a.asistente_type, a.asistente_id)
                    )
                );

            CREATE POLICY justificaciones_insert ON justificaciones FOR INSERT
                WITH CHECK (
                    app_is_privileged()
                    OR EXISTS (
                        SELECT 1 FROM asistencia a
                        WHERE a.id = justificaciones.asistencia_id
                          AND app_can_access_asistente(a.asistente_type, a.asistente_id)
                    )
                );

            CREATE POLICY justificaciones_update ON justificaciones FOR UPDATE
                USING (
                    app_is_privileged()
                    OR EXISTS (
                        SELECT 1 FROM asistencia a
                        WHERE a.id = justificaciones.asistencia_id
                          AND app_can_access_asistente(a.asistente_type, a.asistente_id)
                    )
                )
                WITH CHECK (
                    app_is_privileged()
                    OR EXISTS (
                        SELECT 1 FROM asistencia a
                        WHERE a.id = justificaciones.asistencia_id
                          AND app_can_access_asistente(a.asistente_type, a.asistente_id)
                    )
                );

            CREATE POLICY justificaciones_delete ON justificaciones FOR DELETE
                USING (app_is_privileged());
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS justificaciones_delete ON justificaciones;
            DROP POLICY IF EXISTS justificaciones_update ON justificaciones;
            DROP POLICY IF EXISTS justificaciones_insert ON justificaciones;
            DROP POLICY IF EXISTS justificaciones_select ON justificaciones;
            ALTER TABLE justificaciones NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE justificaciones DISABLE ROW LEVEL SECURITY;
        SQL);
    }
};
