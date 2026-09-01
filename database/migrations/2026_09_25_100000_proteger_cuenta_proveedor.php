<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La cuenta con rol `proveedor` (dueña de la plataforma) quedó con la
 * `parroquia_id` de la parroquia piloto en la migración multi-tenant
 * (2026_08_29_100020). Efecto: el super-admin de esa parroquia la ve en
 * "Usuarios" y —vía las Edge Functions— puede editarla, desactivarla, borrarla
 * o resetear su contraseña.
 *
 * El proveedor es global: no pertenece a ninguna parroquia. Este era el
 * pendiente que 2026_08_30_100000 anotó como "el rol proveedor global llega en
 * la Fase E".
 *
 * Arreglo (todo del lado del servidor):
 *
 *  1. `users.parroquia_id` pasa a NULLABLE y se pone NULL en la(s) cuenta(s)
 *     proveedor. Con eso:
 *       · la RESTRICTIVE `users_parroquia` (app_parroquia_ok) oculta la fila a
 *         cualquier super-admin de parroquia;
 *       · fn_admin_target_auth / fn_admin_guardar_usuario / fn_admin_estado_usuario
 *         / fn_admin_eliminar_usuario ya cortan con "Usuario no encontrado"
 *         cuando la fila objetivo tiene `parroquia_id IS NULL`;
 *       · el hook (custom_access_token_hook) ya emite `parroquia_id: null`
 *         para esa cuenta sin ramas extra.
 *
 *  2. `v_usuarios` excluye explícitamente las filas con rol `proveedor` salvo
 *     que el que consulta sea proveedor (cinturón y tirantes).
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
            -- 1a. parroquia_id deja de ser obligatoria (usuario de plataforma = sin parroquia).
            ALTER TABLE public.users ALTER COLUMN parroquia_id DROP NOT NULL;

            -- 1b. El proveedor es global: sin parroquia.
            UPDATE public.users u
               SET parroquia_id = NULL,
                   updated_at   = now()
             WHERE u.parroquia_id IS NOT NULL
               AND EXISTS (
                   SELECT 1
                     FROM public.model_has_roles m
                     JOIN public.roles r ON r.id = m.role_id
                    WHERE m.model_type = 'App\Models\User'
                      AND m.model_id   = u.id
                      AND r.name       = 'proveedor'
                      AND r.guard_name = 'api'
               );

            -- 2. v_usuarios: nunca lista la cuenta proveedor a un no-proveedor.
            CREATE OR REPLACE VIEW public.v_usuarios WITH (security_invoker = on) AS
            SELECT u.id, u.name, u.email, u.dni,
                   nullif(rtrim(u.celular), '') AS celular,
                   u.fecha_nacimiento,
                   u.activo, u.grupo_id, u.parroquia_id, u.created_at,
                   public.app_user_roles_detalle(u.id)  AS roles,
                   public.app_user_grupos_detalle(u.id) AS grupos
              FROM public.users u
             WHERE public.app_es_proveedor()
                OR NOT EXISTS (
                    SELECT 1
                      FROM public.model_has_roles m
                      JOIN public.roles r ON r.id = m.role_id
                     WHERE m.model_type = 'App\Models\User'
                       AND m.model_id   = u.id
                       AND r.name       = 'proveedor'
                       AND r.guard_name = 'api'
                );

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
            -- Devuelve la vista a su forma sin el filtro.
            CREATE OR REPLACE VIEW public.v_usuarios WITH (security_invoker = on) AS
            SELECT u.id, u.name, u.email, u.dni,
                   nullif(rtrim(u.celular), '') AS celular,
                   u.fecha_nacimiento,
                   u.activo, u.grupo_id, u.parroquia_id, u.created_at,
                   public.app_user_roles_detalle(u.id)  AS roles,
                   public.app_user_grupos_detalle(u.id) AS grupos
              FROM public.users u;
            GRANT SELECT ON public.v_usuarios TO authenticated;

            -- Rebackfill de los NULL a la parroquia piloto antes de reponer el NOT NULL.
            UPDATE public.users
               SET parroquia_id = (SELECT id FROM public.parroquias ORDER BY id LIMIT 1)
             WHERE parroquia_id IS NULL;

            ALTER TABLE public.users ALTER COLUMN parroquia_id SET NOT NULL;

            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
