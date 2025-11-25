<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'ver dashboard',

            // Administración
            'ver usuarios', 'crear usuarios', 'editar usuarios', 'eliminar usuarios',
            'ver roles', 'crear roles', 'editar roles', 'eliminar roles',
            'ver permisos', 'asignar permisos',

            // Grupos y Confirmandos
            'ver grupos', 'ver todos los grupos','crear grupos', 'editar grupos', 'eliminar grupos', 'importar grupos', 
            'asignar catequista', 'ver catequistas', 'asignar confirmandos', 'ver confirmandos', 'ver todos los confirmandos',
            'crear confirmandos', 'editar confirmandos', 'eliminar confirmandos', 'importar confirmandos',

            // Cronograma y Asistencias
            'ver cronograma', 'crear cronograma', 'editar cronograma', 'eliminar cronograma',
            'ver asistencias', 'crear asistencias', 'editar asistencias', 'importar asistencias',

            // Requisitos y Reportes
            'ver requisitos', 'validar requisitos',
            'generar reportes', 'exportar reportes',

            // Mantenimiento
            'ver mantenimiento', 'crear respaldo', 'restaurar respaldo',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'api');
        }

        $roleAdmin = Role::findOrCreate('super-admin', 'api');
        $roleCoordinador = Role::findOrCreate('coordinador', 'api');
        $roleCatequista = Role::findOrCreate('catequista', 'api');

        $roleAdmin->syncPermissions(Permission::all());
        $roleCoordinador->syncPermissions(['ver dashboard',
            'ver grupos', 'ver todos los grupos', 'crear grupos', 'editar grupos', 'eliminar grupos', 'importar grupos', 'asignar catequista',
            'asignar confirmandos', 'ver confirmandos', 'crear confirmandos', 'editar confirmandos', 'ver todos los confirmandos',
            'eliminar confirmandos', 'importar confirmandos', 'ver cronograma', 'crear cronograma', 'editar cronograma',
            'eliminar cronograma', 'generar reportes', 'exportar reportes','editar asistencias',
        ]);
        $roleCatequista->syncPermissions(['ver dashboard', 'ver cronograma', 'ver confirmandos', 'ver asistencias', 'ver catequistas',
            'crear asistencias', 'importar asistencias', 'ver requisitos', 'validar requisitos', 'ver grupos',
        ]);

        User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin User',
                'dni' => '72917370',
                'password' => Hash::make('123456789'),
            ]
        )->assignRole($roleAdmin);

        User::firstOrCreate(
            ['email' => 'coordinador@test.com'],
            [
                'name' => 'Usuario Coordinador',
                'dni' => '72917371',
                'password' => Hash::make('123456789'),
            ]
        )->assignRole($roleCoordinador);

        User::firstOrCreate(
            ['email' => 'cristopher@test.com'],
            [
                'name' => 'Cristopher Carrasco Crisanto',
                'dni' => '72917372',
                'password' => Hash::make('123456789'),
            ]
        )->assignRole($roleCatequista);

        User::firstOrCreate(
            ['email' => 'Domenick@test.com'],
            [
                'name' => 'Domenick Cardoza Guerrero',
                'dni' => '72917373',
                'password' => Hash::make('123456789'),
            ]
        )->assignRole($roleCatequista);

        User::firstOrCreate(
            ['email' => 'Requena@test.com'],
            [
                'name' => 'José Requena Córdoba',
                'dni' => '72917374',
                'password' => Hash::make('123456789'),
            ]
        )->assignRole($roleCatequista);

        User::firstOrCreate(
            ['email' => 'Yenn@test.com'],
            [
                'name' => 'Yennifer Gutierrez',
                'dni' => '72917375',
                'password' => Hash::make('123456789'),
            ]
        )->assignRole($roleCatequista);

        $this->command->info('Permisos, Roles y Usuarios de prueba creados exitosamente.');
    }
}
