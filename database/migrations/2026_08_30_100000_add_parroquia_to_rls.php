<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Capa de aislamiento por parroquia sobre RLS, en las tablas con columna
 * `parroquia_id` directa. Se implementa con políticas RESTRICTIVE (se combinan
 * con AND sobre las políticas permisivas de alcance-por-grupo existentes), así
 * que NO hay que tocar 2026_08_11_120000_enable_row_level_security.
 *
 * Cada política lee `parroquia_id` de la propia fila (sin subconsultas) -> no hay
 * riesgo de recursión entre políticas ni coste de join.
 *
 * Las pivote y asistencia/justificaciones NO llevan política de parroquia aquí:
 * la app siempre las alcanza a través de su modelo padre, que ya está acotado por
 * parroquia (Global Scope + esta RLS). Su RLS de alcance-por-grupo sigue vigente.
 *
 * `app.current_parroquia_id` lo fija SetPostgresRlsContext. Vacío (CLI, login,
 * público) => sin filtro. NADIE lo salta por ser "privilegiado" (un super-admin de
 * la parroquia A no ve la B); el rol proveedor global llega en la Fase E.
 *
 * Solo pgsql.
 */
return new class extends Migration
{
    /** Ya tienen RLS (alcance por grupo) y columna parroquia_id. */
    private array $conRlsPrevia = ['grupos', 'confirmandos', 'apoderados'];

    /** Sin RLS previa, con columna parroquia_id. */
    private array $sinRlsPrevia = ['reunions', 'sacramentos', 'requisitos', 'tipo_apoderados', 'users', 'frontend_error_logs'];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_current_parroquia_id() RETURNS bigint AS $$
                SELECT NULLIF(current_setting('app.current_parroquia_id', true), '')::bigint
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION app_parroquia_ok(p_parroquia_id bigint) RETURNS boolean AS $$
                SELECT app_current_parroquia_id() IS NULL OR p_parroquia_id = app_current_parroquia_id()
            $$ LANGUAGE sql STABLE;
        SQL);

        foreach ($this->conRlsPrevia as $tabla) {
            DB::unprepared("
                CREATE POLICY {$tabla}_parroquia ON {$tabla} AS RESTRICTIVE FOR ALL
                    USING (app_parroquia_ok(parroquia_id))
                    WITH CHECK (app_parroquia_ok(parroquia_id));
            ");
        }

        foreach ($this->sinRlsPrevia as $tabla) {
            DB::unprepared("
                ALTER TABLE {$tabla} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE {$tabla} FORCE ROW LEVEL SECURITY;
                CREATE POLICY {$tabla}_all ON {$tabla} FOR ALL USING (true) WITH CHECK (true);
                CREATE POLICY {$tabla}_parroquia ON {$tabla} AS RESTRICTIVE FOR ALL
                    USING (app_parroquia_ok(parroquia_id))
                    WITH CHECK (app_parroquia_ok(parroquia_id));
            ");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->conRlsPrevia as $tabla) {
            DB::unprepared("DROP POLICY IF EXISTS {$tabla}_parroquia ON {$tabla};");
        }

        foreach ($this->sinRlsPrevia as $tabla) {
            DB::unprepared("
                DROP POLICY IF EXISTS {$tabla}_parroquia ON {$tabla};
                DROP POLICY IF EXISTS {$tabla}_all ON {$tabla};
                ALTER TABLE {$tabla} NO FORCE ROW LEVEL SECURITY;
                ALTER TABLE {$tabla} DISABLE ROW LEVEL SECURITY;
            ");
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS app_parroquia_ok(bigint);
            DROP FUNCTION IF EXISTS app_current_parroquia_id();
        SQL);
    }
};
