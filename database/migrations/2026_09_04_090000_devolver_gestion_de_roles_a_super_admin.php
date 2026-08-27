<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * La Parte 5 le quitó al super-admin (admin de parroquia) la gestión de roles.
 * Con una sola parroquia real eso es demasiado restrictivo: el admin necesita
 * poder crear/editar los roles de su parroquia. Se le devuelven esos permisos.
 *
 * El proveedor (dueño de la plataforma) conserva el control del catálogo de
 * PERMISOS (crear/editar/eliminar permisos) — eso sí sigue siendo suyo, porque un
 * permiso nuevo requiere código nuevo en el backend.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $superAdmin = Role::where('name', 'super-admin')->where('guard_name', 'api')->first();

        $superAdmin?->givePermissionTo(
            Permission::whereIn('name', [
                'crear roles', 'editar roles', 'eliminar roles', 'ver permisos', 'asignar permisos',
            ])->get()
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $superAdmin = Role::where('name', 'super-admin')->where('guard_name', 'api')->first();

        $superAdmin?->revokePermissionTo(
            Permission::whereIn('name', [
                'crear roles', 'editar roles', 'eliminar roles', 'ver permisos', 'asignar permisos',
            ])->get()
        );
    }
};
