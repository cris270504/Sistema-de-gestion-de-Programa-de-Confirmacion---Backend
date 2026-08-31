<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2 de la migración a Supabase (docs/PLAN-MIGRACION-SUPABASE.md).
 *
 * La RLS pasa a ser el ÚNICO guardián de tenant/alcance, leyendo los claims del
 * JWT (`request.jwt.claims`) en vez de las variables de sesión `app.current_*`.
 *
 * - PostgREST ya pone `request.jwt.claims` con el JWT de Supabase Auth.
 * - `App\Http\Middleware\SetPostgresRlsContext` pone el MISMO claim (sintético,
 *   desde el usuario) para las peticiones que todavía sirve Laravel.
 *
 * Efecto: `set_config('app.current_*')` desaparece; sirve con cualquier pooler y
 * con las conexiones efímeras de PostgREST/Edge Functions.
 *
 * Solo pgsql (la suite corre en sqlite y no soporta CREATE POLICY).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // ── 1. Helpers: de current_setting('app.*') a los claims del JWT ────────
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_jwt() RETURNS jsonb
                LANGUAGE sql STABLE AS $$
                SELECT COALESCE(
                    NULLIF(current_setting('request.jwt.claims', true), ''),
                    '{}'
                )::jsonb
            $$;

            CREATE OR REPLACE FUNCTION public.app_current_user_id() RETURNS bigint
                LANGUAGE sql STABLE AS $$
                SELECT NULLIF(public.app_jwt() ->> 'app_user_id', '')::bigint
            $$;

            CREATE OR REPLACE FUNCTION public.app_current_parroquia_id() RETURNS bigint
                LANGUAGE sql STABLE AS $$
                SELECT NULLIF(public.app_jwt() ->> 'parroquia_id', '')::bigint
            $$;

            CREATE OR REPLACE FUNCTION public.app_es_proveedor() RETURNS boolean
                LANGUAGE sql STABLE AS $$
                SELECT COALESCE((public.app_jwt() ->> 'es_proveedor')::boolean, false)
            $$;

            -- coordinador / super-admin / proveedor: se saltan el filtro POR GRUPO
            -- (siguen acotados por parroquia salvo que es_proveedor sea true).
            CREATE OR REPLACE FUNCTION public.app_is_privileged() RETURNS boolean
                LANGUAGE sql STABLE AS $$
                SELECT public.app_es_proveedor()
                    OR COALESCE(
                        ARRAY(SELECT jsonb_array_elements_text(public.app_jwt() -> 'roles'))
                            && ARRAY['coordinador','super-admin','proveedor']::text[],
                        false
                    )
            $$;

            -- Gate de parroquia (RESTRICTIVE). NULL = sin contexto (CLI/público).
            -- es_proveedor global = sin filtro de parroquia.
            CREATE OR REPLACE FUNCTION public.app_parroquia_ok(p_parroquia_id bigint) RETURNS boolean
                LANGUAGE sql STABLE AS $$
                SELECT public.app_current_parroquia_id() IS NULL
                    OR public.app_es_proveedor()
                    OR p_parroquia_id = public.app_current_parroquia_id()
            $$;

            -- Sin cambio de cuerpo: ahora app_current_user_id() sale del claim.
            CREATE OR REPLACE FUNCTION public.app_user_grupo_ids() RETURNS SETOF bigint
                LANGUAGE sql STABLE AS $$
                SELECT grupo_id FROM public.catequista_grupo WHERE user_id = public.app_current_user_id()
            $$;
        SQL);

        // ── 2. confirmandos + catequista_grupo: del esquema `app.jwt_*` (spike
        //      de la Fase 0) de vuelta al patrón estándar public.app_* ──────────
        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS confirmandos_select    ON public.confirmandos;
            DROP POLICY IF EXISTS confirmandos_insert    ON public.confirmandos;
            DROP POLICY IF EXISTS confirmandos_update    ON public.confirmandos;
            DROP POLICY IF EXISTS confirmandos_delete    ON public.confirmandos;
            DROP POLICY IF EXISTS confirmandos_parroquia ON public.confirmandos;

            CREATE POLICY confirmandos_parroquia ON public.confirmandos AS RESTRICTIVE FOR ALL
                USING (public.app_parroquia_ok(parroquia_id))
                WITH CHECK (public.app_parroquia_ok(parroquia_id));
            CREATE POLICY confirmandos_select ON public.confirmandos FOR SELECT
                USING (public.app_is_privileged() OR grupo_id IN (SELECT public.app_user_grupo_ids()));
            CREATE POLICY confirmandos_insert ON public.confirmandos FOR INSERT
                WITH CHECK (public.app_is_privileged());
            CREATE POLICY confirmandos_update ON public.confirmandos FOR UPDATE
                USING (public.app_is_privileged()) WITH CHECK (public.app_is_privileged());
            CREATE POLICY confirmandos_delete ON public.confirmandos FOR DELETE
                USING (public.app_is_privileged());

            DROP POLICY IF EXISTS catequista_grupo_select ON public.catequista_grupo;
            DROP POLICY IF EXISTS catequista_grupo_insert ON public.catequista_grupo;
            DROP POLICY IF EXISTS catequista_grupo_update ON public.catequista_grupo;
            DROP POLICY IF EXISTS catequista_grupo_delete ON public.catequista_grupo;

            CREATE POLICY catequista_grupo_select ON public.catequista_grupo FOR SELECT
                USING (public.app_is_privileged() OR user_id = public.app_current_user_id());
            CREATE POLICY catequista_grupo_insert ON public.catequista_grupo FOR INSERT
                WITH CHECK (public.app_is_privileged());
            CREATE POLICY catequista_grupo_update ON public.catequista_grupo FOR UPDATE
                USING (public.app_is_privileged()) WITH CHECK (public.app_is_privileged());
            CREATE POLICY catequista_grupo_delete ON public.catequista_grupo FOR DELETE
                USING (public.app_is_privileged());

            -- El esquema `app` del spike ya no se usa.
            DROP SCHEMA IF EXISTS app CASCADE;
        SQL);

        // ── 3. Reemplazar los `_all USING(true)` (demasiado abiertos para
        //      PostgREST) por políticas reales. La RESTRICTIVE `_parroquia`
        //      sigue vigente y se combina con AND. ──────────────────────────────
        foreach (['reunions', 'sacramentos', 'requisitos', 'tipo_apoderados'] as $t) {
            DB::unprepared("
                DROP POLICY IF EXISTS {$t}_all ON public.{$t};
                DROP POLICY IF EXISTS {$t}_select ON public.{$t};
                DROP POLICY IF EXISTS {$t}_write ON public.{$t};
                -- Catálogo de la parroquia: lo lee cualquier usuario con contexto.
                CREATE POLICY {$t}_select ON public.{$t} FOR SELECT
                    USING (public.app_current_parroquia_id() IS NOT NULL OR public.app_es_proveedor());
                CREATE POLICY {$t}_write ON public.{$t} FOR ALL
                    USING (public.app_is_privileged()) WITH CHECK (public.app_is_privileged());
            ");
        }

        DB::unprepared(<<<'SQL'
            -- parroquia_configuraciones (1:1 con la parroquia)
            DROP POLICY IF EXISTS parroquia_configuraciones_all ON public.parroquia_configuraciones;
            CREATE POLICY parroquia_configuraciones_select ON public.parroquia_configuraciones FOR SELECT
                USING (public.app_current_parroquia_id() IS NOT NULL OR public.app_es_proveedor());
            CREATE POLICY parroquia_configuraciones_write ON public.parroquia_configuraciones FOR ALL
                USING (public.app_is_privileged()) WITH CHECK (public.app_is_privileged());

            -- users: cada uno se ve a sí mismo; privilegiados ven su parroquia.
            DROP POLICY IF EXISTS users_all ON public.users;
            CREATE POLICY users_select ON public.users FOR SELECT
                USING (public.app_is_privileged() OR id = public.app_current_user_id());
            CREATE POLICY users_write ON public.users FOR ALL
                USING (public.app_is_privileged()) WITH CHECK (public.app_is_privileged());

            -- frontend_error_logs: el _all tapaba a las políticas específicas.
            DROP POLICY IF EXISTS frontend_error_logs_all ON public.frontend_error_logs;
            DROP POLICY IF EXISTS frontend_error_logs_update ON public.frontend_error_logs;
            CREATE POLICY frontend_error_logs_update ON public.frontend_error_logs FOR UPDATE
                USING (public.app_is_privileged()) WITH CHECK (public.app_is_privileged());
        SQL);

        // ── 4. RLS nueva en tablas que no la tenían ────────────────────────────
        //      parroquias (tabla tenant) + pivotes de dominio (alcance transitivo
        //      vía el padre, que ya está RLS-acotado).
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.parroquias ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.parroquias FORCE ROW LEVEL SECURITY;
            CREATE POLICY parroquias_select ON public.parroquias FOR SELECT
                USING (id = public.app_current_parroquia_id() OR public.app_es_proveedor());
            CREATE POLICY parroquias_write ON public.parroquias FOR ALL
                USING (public.app_es_proveedor()) WITH CHECK (public.app_es_proveedor());

            ALTER TABLE public.confirmando_sacramento ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.confirmando_sacramento FORCE ROW LEVEL SECURITY;
            CREATE POLICY confirmando_sacramento_all ON public.confirmando_sacramento FOR ALL
                USING (confirmando_id IN (SELECT id FROM public.confirmandos))
                WITH CHECK (confirmando_id IN (SELECT id FROM public.confirmandos));

            ALTER TABLE public.confirmando_requisito ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.confirmando_requisito FORCE ROW LEVEL SECURITY;
            CREATE POLICY confirmando_requisito_all ON public.confirmando_requisito FOR ALL
                USING (confirmando_id IN (SELECT id FROM public.confirmandos))
                WITH CHECK (confirmando_id IN (SELECT id FROM public.confirmandos));

            ALTER TABLE public.sacramento_requisito ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.sacramento_requisito FORCE ROW LEVEL SECURITY;
            CREATE POLICY sacramento_requisito_all ON public.sacramento_requisito FOR ALL
                USING (sacramento_id IN (SELECT id FROM public.sacramentos))
                WITH CHECK (sacramento_id IN (SELECT id FROM public.sacramentos));

            ALTER TABLE public.reunion_user ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.reunion_user FORCE ROW LEVEL SECURITY;
            CREATE POLICY reunion_user_all ON public.reunion_user FOR ALL
                USING (reunion_id IN (SELECT id FROM public.reunions))
                WITH CHECK (reunion_id IN (SELECT id FROM public.reunions));
        SQL);

        // ── 5. Tablas de infraestructura / Spatie: fuera del alcance de PostgREST.
        //      Laravel las usa con el rol `postgres` (dueño), no le afecta.
        $infra = [
            'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions',
            'password_reset_tokens', 'migrations',
            'oauth_access_tokens', 'oauth_auth_codes', 'oauth_clients',
            'oauth_device_codes', 'oauth_refresh_tokens',
            'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        ];
        foreach ($infra as $t) {
            DB::unprepared("
                REVOKE ALL ON public.{$t} FROM anon, authenticated;
                ALTER TABLE public.{$t} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE public.{$t} FORCE ROW LEVEL SECURITY;
            ");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Restaura los helpers a la versión "variables de sesión".
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_current_user_id() RETURNS bigint AS $$
                SELECT NULLIF(current_setting('app.current_user_id', true), '')::bigint
            $$ LANGUAGE sql STABLE;
            CREATE OR REPLACE FUNCTION public.app_is_privileged() RETURNS boolean AS $$
                SELECT COALESCE(NULLIF(current_setting('app.current_user_privileged', true), ''), 'false')::boolean
            $$ LANGUAGE sql STABLE;
            CREATE OR REPLACE FUNCTION public.app_current_parroquia_id() RETURNS bigint AS $$
                SELECT NULLIF(current_setting('app.current_parroquia_id', true), '')::bigint
            $$ LANGUAGE sql STABLE;
            CREATE OR REPLACE FUNCTION public.app_parroquia_ok(p_parroquia_id bigint) RETURNS boolean AS $$
                SELECT app_current_parroquia_id() IS NULL OR p_parroquia_id = app_current_parroquia_id()
            $$ LANGUAGE sql STABLE;
            CREATE OR REPLACE FUNCTION public.app_user_grupo_ids() RETURNS SETOF bigint AS $$
                SELECT grupo_id FROM catequista_grupo WHERE user_id = app_current_user_id()
            $$ LANGUAGE sql STABLE;
            DROP FUNCTION IF EXISTS public.app_es_proveedor();
            DROP FUNCTION IF EXISTS public.app_jwt();

            DROP POLICY IF EXISTS parroquias_select ON public.parroquias;
            DROP POLICY IF EXISTS parroquias_write ON public.parroquias;
            ALTER TABLE public.parroquias NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE public.parroquias DISABLE ROW LEVEL SECURITY;
        SQL);

        foreach (['confirmando_sacramento', 'confirmando_requisito', 'sacramento_requisito', 'reunion_user'] as $t) {
            DB::unprepared("
                DROP POLICY IF EXISTS {$t}_all ON public.{$t};
                ALTER TABLE public.{$t} NO FORCE ROW LEVEL SECURITY;
                ALTER TABLE public.{$t} DISABLE ROW LEVEL SECURITY;
            ");
        }

        foreach (['reunions', 'sacramentos', 'requisitos', 'tipo_apoderados'] as $t) {
            DB::unprepared("
                DROP POLICY IF EXISTS {$t}_select ON public.{$t};
                DROP POLICY IF EXISTS {$t}_write ON public.{$t};
                CREATE POLICY {$t}_all ON public.{$t} FOR ALL USING (true) WITH CHECK (true);
            ");
        }

        // (No se restauran las políticas exactas del spike en confirmandos/
        //  catequista_grupo ni los grants de infra: este down es de emergencia.)
    }
};
