<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row Level Security para las tablas de confirmandos, grupos, apoderados y asistencias.
 *
 * Modelo de confianza (una sola credencial de Postgres para toda la app):
 * - HTTP (auth:api): App\Http\Middleware\SetPostgresRlsContext fija, por request,
 *   `app.current_user_id` y `app.current_user_privileged` (coordinador/super-admin = true).
 * - CLI (artisan migrate/seed/tinker/queue:work): AppServiceProvider::boot() marca la
 *   sesión como privilegiada porque corre con credenciales de despliegue, no de un usuario final.
 *
 * Como el rol de conexión es dueño de las tablas, se usa FORCE ROW LEVEL SECURITY para que
 * las políticas también apliquen a ese rol (Postgres exime al dueño por defecto).
 *
 * Solo aplica sobre pgsql: la suite de tests corre en sqlite (phpunit.xml) y no soporta
 * CREATE POLICY / RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            -- === Funciones auxiliares =========================================

            CREATE OR REPLACE FUNCTION app_current_user_id() RETURNS bigint AS $$
                SELECT NULLIF(current_setting('app.current_user_id', true), '')::bigint
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION app_is_privileged() RETURNS boolean AS $$
                SELECT COALESCE(NULLIF(current_setting('app.current_user_privileged', true), ''), 'false')::boolean
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION app_user_grupo_ids() RETURNS SETOF bigint AS $$
                SELECT grupo_id FROM catequista_grupo WHERE user_id = app_current_user_id()
            $$ LANGUAGE sql STABLE;

            -- Resuelve si el usuario actual puede leer/escribir un registro polimórfico
            -- de asistencia (asistente_type/asistente_id) según su alcance de grupos.
            CREATE OR REPLACE FUNCTION app_can_access_asistente(p_type text, p_id bigint) RETURNS boolean AS $$
                SELECT CASE p_type
                    WHEN 'App\Models\Confirmando' THEN EXISTS (
                        SELECT 1 FROM confirmandos
                        WHERE id = p_id AND grupo_id IN (SELECT app_user_grupo_ids())
                    )
                    WHEN 'App\Models\Apoderado' THEN EXISTS (
                        SELECT 1 FROM confirmando_apoderado ca
                        JOIN confirmandos c ON c.id = ca.confirmando_id
                        WHERE ca.apoderado_id = p_id AND c.grupo_id IN (SELECT app_user_grupo_ids())
                    )
                    WHEN 'App\Models\User' THEN p_id = app_current_user_id()
                    ELSE false
                END
            $$ LANGUAGE sql STABLE;

            -- === grupos =========================================================
            ALTER TABLE grupos ENABLE ROW LEVEL SECURITY;
            ALTER TABLE grupos FORCE ROW LEVEL SECURITY;

            CREATE POLICY grupos_select ON grupos FOR SELECT
                USING (app_is_privileged() OR id IN (SELECT app_user_grupo_ids()));

            CREATE POLICY grupos_insert ON grupos FOR INSERT
                WITH CHECK (app_is_privileged());

            CREATE POLICY grupos_update ON grupos FOR UPDATE
                USING (app_is_privileged())
                WITH CHECK (app_is_privileged());

            CREATE POLICY grupos_delete ON grupos FOR DELETE
                USING (app_is_privileged());

            -- === catequista_grupo (pivote catequista <-> grupo) ================
            ALTER TABLE catequista_grupo ENABLE ROW LEVEL SECURITY;
            ALTER TABLE catequista_grupo FORCE ROW LEVEL SECURITY;

            CREATE POLICY catequista_grupo_select ON catequista_grupo FOR SELECT
                USING (app_is_privileged() OR user_id = app_current_user_id());

            CREATE POLICY catequista_grupo_insert ON catequista_grupo FOR INSERT
                WITH CHECK (app_is_privileged());

            CREATE POLICY catequista_grupo_update ON catequista_grupo FOR UPDATE
                USING (app_is_privileged())
                WITH CHECK (app_is_privileged());

            CREATE POLICY catequista_grupo_delete ON catequista_grupo FOR DELETE
                USING (app_is_privileged());

            -- === confirmandos ===================================================
            ALTER TABLE confirmandos ENABLE ROW LEVEL SECURITY;
            ALTER TABLE confirmandos FORCE ROW LEVEL SECURITY;

            CREATE POLICY confirmandos_select ON confirmandos FOR SELECT
                USING (app_is_privileged() OR grupo_id IN (SELECT app_user_grupo_ids()));

            CREATE POLICY confirmandos_insert ON confirmandos FOR INSERT
                WITH CHECK (app_is_privileged());

            CREATE POLICY confirmandos_update ON confirmandos FOR UPDATE
                USING (app_is_privileged())
                WITH CHECK (app_is_privileged());

            CREATE POLICY confirmandos_delete ON confirmandos FOR DELETE
                USING (app_is_privileged());

            -- === apoderados (alcance vía confirmando_apoderado -> confirmandos) =
            ALTER TABLE apoderados ENABLE ROW LEVEL SECURITY;
            ALTER TABLE apoderados FORCE ROW LEVEL SECURITY;

            CREATE POLICY apoderados_select ON apoderados FOR SELECT
                USING (
                    app_is_privileged()
                    OR id IN (
                        SELECT ca.apoderado_id
                        FROM confirmando_apoderado ca
                        JOIN confirmandos c ON c.id = ca.confirmando_id
                        WHERE c.grupo_id IN (SELECT app_user_grupo_ids())
                    )
                );

            CREATE POLICY apoderados_insert ON apoderados FOR INSERT
                WITH CHECK (app_is_privileged());

            CREATE POLICY apoderados_update ON apoderados FOR UPDATE
                USING (app_is_privileged())
                WITH CHECK (app_is_privileged());

            CREATE POLICY apoderados_delete ON apoderados FOR DELETE
                USING (app_is_privileged());

            -- === confirmando_apoderado (pivote confirmando <-> apoderado) =======
            ALTER TABLE confirmando_apoderado ENABLE ROW LEVEL SECURITY;
            ALTER TABLE confirmando_apoderado FORCE ROW LEVEL SECURITY;

            CREATE POLICY confirmando_apoderado_select ON confirmando_apoderado FOR SELECT
                USING (
                    app_is_privileged()
                    OR confirmando_id IN (
                        SELECT id FROM confirmandos WHERE grupo_id IN (SELECT app_user_grupo_ids())
                    )
                );

            CREATE POLICY confirmando_apoderado_insert ON confirmando_apoderado FOR INSERT
                WITH CHECK (app_is_privileged());

            CREATE POLICY confirmando_apoderado_update ON confirmando_apoderado FOR UPDATE
                USING (app_is_privileged())
                WITH CHECK (app_is_privileged());

            CREATE POLICY confirmando_apoderado_delete ON confirmando_apoderado FOR DELETE
                USING (app_is_privileged());

            -- === asistencia (polimórfica: Confirmando | Apoderado | User) ======
            ALTER TABLE asistencia ENABLE ROW LEVEL SECURITY;
            ALTER TABLE asistencia FORCE ROW LEVEL SECURITY;

            CREATE POLICY asistencia_select ON asistencia FOR SELECT
                USING (app_is_privileged() OR app_can_access_asistente(asistente_type, asistente_id));

            CREATE POLICY asistencia_insert ON asistencia FOR INSERT
                WITH CHECK (
                    app_is_privileged()
                    OR (
                        asistente_type IN ('App\Models\Confirmando', 'App\Models\Apoderado')
                        AND app_can_access_asistente(asistente_type, asistente_id)
                    )
                );

            CREATE POLICY asistencia_update ON asistencia FOR UPDATE
                USING (
                    app_is_privileged()
                    OR (
                        asistente_type IN ('App\Models\Confirmando', 'App\Models\Apoderado')
                        AND app_can_access_asistente(asistente_type, asistente_id)
                    )
                )
                WITH CHECK (
                    app_is_privileged()
                    OR (
                        asistente_type IN ('App\Models\Confirmando', 'App\Models\Apoderado')
                        AND app_can_access_asistente(asistente_type, asistente_id)
                    )
                );

            CREATE POLICY asistencia_delete ON asistencia FOR DELETE
                USING (app_is_privileged());
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS asistencia_delete ON asistencia;
            DROP POLICY IF EXISTS asistencia_update ON asistencia;
            DROP POLICY IF EXISTS asistencia_insert ON asistencia;
            DROP POLICY IF EXISTS asistencia_select ON asistencia;
            ALTER TABLE asistencia NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE asistencia DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS confirmando_apoderado_delete ON confirmando_apoderado;
            DROP POLICY IF EXISTS confirmando_apoderado_update ON confirmando_apoderado;
            DROP POLICY IF EXISTS confirmando_apoderado_insert ON confirmando_apoderado;
            DROP POLICY IF EXISTS confirmando_apoderado_select ON confirmando_apoderado;
            ALTER TABLE confirmando_apoderado NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE confirmando_apoderado DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS apoderados_delete ON apoderados;
            DROP POLICY IF EXISTS apoderados_update ON apoderados;
            DROP POLICY IF EXISTS apoderados_insert ON apoderados;
            DROP POLICY IF EXISTS apoderados_select ON apoderados;
            ALTER TABLE apoderados NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE apoderados DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS confirmandos_delete ON confirmandos;
            DROP POLICY IF EXISTS confirmandos_update ON confirmandos;
            DROP POLICY IF EXISTS confirmandos_insert ON confirmandos;
            DROP POLICY IF EXISTS confirmandos_select ON confirmandos;
            ALTER TABLE confirmandos NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE confirmandos DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS catequista_grupo_delete ON catequista_grupo;
            DROP POLICY IF EXISTS catequista_grupo_update ON catequista_grupo;
            DROP POLICY IF EXISTS catequista_grupo_insert ON catequista_grupo;
            DROP POLICY IF EXISTS catequista_grupo_select ON catequista_grupo;
            ALTER TABLE catequista_grupo NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE catequista_grupo DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS grupos_delete ON grupos;
            DROP POLICY IF EXISTS grupos_update ON grupos;
            DROP POLICY IF EXISTS grupos_insert ON grupos;
            DROP POLICY IF EXISTS grupos_select ON grupos;
            ALTER TABLE grupos NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE grupos DISABLE ROW LEVEL SECURITY;

            DROP FUNCTION IF EXISTS app_can_access_asistente(text, bigint);
            DROP FUNCTION IF EXISTS app_user_grupo_ids();
            DROP FUNCTION IF EXISTS app_is_privileged();
            DROP FUNCTION IF EXISTS app_current_user_id();
        SQL);
    }
};
