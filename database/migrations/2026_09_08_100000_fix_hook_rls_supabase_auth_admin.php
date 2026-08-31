<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2 — arreglo: el Custom Access Token Hook debe poder leer users + tablas
 * de Spatie en Supabase CLOUD.
 *
 * Contexto: la Fase 2 (`2026_09_07_100000_fase2_rls_por_claims`) puso RLS FORCE
 * en `roles/permissions/model_has_*` (sin política) y reemplazó `users_all
 * USING(true)` por una política restrictiva por identidad.
 *
 * En el stack LOCAL el hook seguía andando porque la función es SECURITY DEFINER
 * y el rol `postgres` local es superusuario (se salta la RLS). En Supabase CLOUD
 * `postgres` NO es superusuario y NO tiene BYPASSRLS → el hook se quedaría sin
 * poder leer nada → el JWT saldría sin claims (login roto).
 *
 * Patrón canónico de Supabase para hooks: la función corre como
 * `supabase_auth_admin` (SECURITY INVOKER) y cada tabla que consulta lleva una
 * política permisiva `TO supabase_auth_admin USING (true)`.
 *
 * Solo pgsql.
 */
return new class extends Migration
{
    /** Tablas que consulta public.custom_access_token_hook(). */
    private array $tablas = [
        'users', 'roles', 'permissions',
        'model_has_roles', 'model_has_permissions', 'role_has_permissions',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // 1. El hook corre como quien lo llama (supabase_auth_admin), no como owner.
        DB::unprepared('ALTER FUNCTION public.custom_access_token_hook(jsonb) SECURITY INVOKER;');

        // 2. Acceso de lectura para el rol del hook en cada tabla que consulta.
        foreach ($this->tablas as $t) {
            DB::unprepared("
                GRANT SELECT ON public.{$t} TO supabase_auth_admin;
                DROP POLICY IF EXISTS {$t}_auth_admin_read ON public.{$t};
                CREATE POLICY {$t}_auth_admin_read ON public.{$t}
                    AS PERMISSIVE FOR SELECT
                    TO supabase_auth_admin
                    USING (true);
            ");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tablas as $t) {
            DB::unprepared("DROP POLICY IF EXISTS {$t}_auth_admin_read ON public.{$t};");
        }

        DB::unprepared('ALTER FUNCTION public.custom_access_token_hook(jsonb) SECURITY DEFINER;');
    }
};
