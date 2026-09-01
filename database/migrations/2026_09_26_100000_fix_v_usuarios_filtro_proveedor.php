<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix de 2026_09_25_100000: el filtro de proveedor en `v_usuarios` consultaba
 * `public.model_has_roles` / `public.roles` en el WHERE. Como la vista es
 * `security_invoker = on`, ese subquery corre como `authenticated`, que NO tiene
 * SELECT sobre las tablas de Spatie (revocado en Fase 2) → "permission denied
 * for table model_has_roles" y la lista de usuarios quedaba vacía.
 *
 * Se reescribe el filtro usando `app_user_roles_detalle(u.id)` — que YA es
 * SECURITY DEFINER y ya se calcula en el SELECT — en vez de tocar las tablas.
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
            CREATE OR REPLACE VIEW public.v_usuarios WITH (security_invoker = on) AS
            SELECT u.id, u.name, u.email, u.dni,
                   nullif(rtrim(u.celular), '') AS celular,
                   u.fecha_nacimiento,
                   u.activo, u.grupo_id, u.parroquia_id, u.created_at,
                   public.app_user_roles_detalle(u.id)  AS roles,
                   public.app_user_grupos_detalle(u.id) AS grupos
              FROM public.users u
             WHERE public.app_es_proveedor()
                OR NOT (public.app_user_roles_detalle(u.id) @> '[{"name": "proveedor"}]'::jsonb);

            GRANT SELECT ON public.v_usuarios TO authenticated;

            NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW public.v_usuarios WITH (security_invoker = on) AS
            SELECT u.id, u.name, u.email, u.dni,
                   nullif(rtrim(u.celular), '') AS celular,
                   u.fecha_nacimiento,
                   u.activo, u.grupo_id, u.parroquia_id, u.created_at,
                   public.app_user_roles_detalle(u.id)  AS roles,
                   public.app_user_grupos_detalle(u.id) AS grupos
              FROM public.users u;

            GRANT SELECT ON public.v_usuarios TO authenticated;

            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
