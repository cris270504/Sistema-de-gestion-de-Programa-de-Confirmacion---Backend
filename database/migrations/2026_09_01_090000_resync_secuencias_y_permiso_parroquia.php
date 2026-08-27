<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 1) Resincroniza TODAS las secuencias de identidad de Postgres con el MAX(id) de
 *    su tabla. Necesario porque la base se importó desde un dump SQL y las
 *    secuencias quedaron en 1 pese a tener filas → cualquier INSERT nuevo choca
 *    con "duplicate key value violates unique constraint" (p. ej. al crear el
 *    permiso 'administrar parroquia'). Idempotente: si ya están sincronizadas, no-op.
 *
 * 2) Crea el permiso 'administrar parroquia' y se lo da a super-admin, sin depender
 *    de re-ejecutar el seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $columnas = DB::select(<<<'SQL'
                SELECT c.relname AS tabla, a.attname AS columna
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                JOIN pg_attribute a ON a.attrelid = c.oid
                JOIN pg_attrdef d ON d.adrelid = c.oid AND d.adnum = a.attnum
                WHERE c.relkind = 'r'
                  AND n.nspname = 'public'
                  AND pg_get_expr(d.adbin, d.adrelid) LIKE 'nextval%'
            SQL);

            foreach ($columnas as $col) {
                DB::statement(
                    "SELECT setval(
                        pg_get_serial_sequence(?, ?),
                        COALESCE((SELECT MAX({$col->columna}) FROM {$col->tabla}), 1),
                        (SELECT MAX({$col->columna}) FROM {$col->tabla}) IS NOT NULL
                    )",
                    [$col->tabla, $col->columna]
                );
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permiso = Permission::findOrCreate('administrar parroquia', 'api');

        $superAdmin = Role::where('name', 'super-admin')->where('guard_name', 'api')->first();
        $superAdmin?->givePermissionTo($permiso);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // La resincronización de secuencias no se revierte (no tiene sentido).
        Permission::where('name', 'administrar parroquia')->where('guard_name', 'api')->delete();
    }
};
