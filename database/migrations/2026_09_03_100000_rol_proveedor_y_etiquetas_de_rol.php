<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles de dos niveles:
 * - `proveedor` (global): dueño de la plataforma. Ve/gestiona todas las parroquias
 *   y el catálogo de roles/permisos. Permiso `administrar plataforma`.
 * - `super-admin` sigue siendo el admin de UNA parroquia (ya acotado por el Global
 *   Scope + RLS); pierde la gestión del catálogo global de roles/permisos.
 *
 * Además: `roles_labels` (jsonb) en la config de la parroquia para renombrar los
 * roles solo en la interfaz (el backend sigue chequeando el nombre interno).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parroquia_configuraciones', function (Blueprint $table) {
            $table->json('roles_labels')->nullable()->after('branding');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $administrarPlataforma = Permission::findOrCreate('administrar plataforma', 'api');

        $proveedor = Role::findOrCreate('proveedor', 'api');
        $proveedor->syncPermissions(Permission::all()); // el proveedor puede todo

        // super-admin ya no CREA/EDITA el catálogo global de roles/permisos (esas
        // rutas pasan a exigir 'administrar plataforma'). Conserva 'ver roles' para
        // poder asignar roles a los usuarios de su parroquia.
        $superAdmin = Role::where('name', 'super-admin')->where('guard_name', 'api')->first();
        $superAdmin?->revokePermissionTo(
            Permission::whereIn('name', [
                'crear roles', 'editar roles', 'eliminar roles',
                'ver permisos', 'crear permisos', 'editar permisos', 'eliminar permisos',
                'asignar permisos',
            ])->get()
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('parroquia_configuraciones', function (Blueprint $table) {
            $table->dropColumn('roles_labels');
        });

        Role::where('name', 'proveedor')->where('guard_name', 'api')->delete();
        Permission::where('name', 'administrar plataforma')->where('guard_name', 'api')->delete();
    }
};
