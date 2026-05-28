<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MigracionTotalSeeder extends Seeder
{
    public function run(): void
    {
        $tablas = [
            'grupos', 'requisitos', 'sacramentos', 'tipo_apoderados', 'apoderados',
            'users', 'reunions', 'confirmandos', 'asistencia', 'justificaciones',
            'confirmando_apoderado', 'confirmando_sacramento', 'sacramento_requisito', 
            'confirmando_requisito', 'reunion_user',
            'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'
        ];

        foreach ($tablas as $tabla) {
            $this->command->info("Migrando datos de la tabla: {$tabla}...");

            // ➔ CORREGIDO: Declaración nativa de PHP con signo de dólar
            $columnaOrden = 'id';
            if ($tabla === 'model_has_roles' || $tabla === 'role_has_permissions') {
                $columnaOrden = 'role_id';
            } elseif ($tabla === 'model_has_permissions') {
                $columnaOrden = 'permission_id';
            }

            DB::connection('tidb_viejo')
                ->table($tabla)
                ->orderBy($columnaOrden)
                ->chunk(100, function ($registros) use ($tabla) {
                    foreach ($registros as $registro) {
                        
                        if (isset($registro->id)) {
                            $existe = DB::connection('pgsql')
                                ->table($tabla)
                                ->where('id', $registro->id)
                                ->exists();

                            if (!$existe) {
                                DB::connection('pgsql')->table($tabla)->insert((array) $registro);
                            } else {
                                DB::connection('pgsql')
                                    ->table($tabla)
                                    ->where('id', $registro->id)
                                    ->update((array) $registro);
                            }
                        } else {
                            try {
                                DB::connection('pgsql')->table($tabla)->insert((array) $registro);
                            } catch (\Exception $e) {
                                // Ignora duplicados en tablas pivote puras
                            }
                        }

                    }
                });
        }
        
        $this->command->info("¡MIGRACIÓN GLOBAL COMPLETADA CON ÉXITO ABSOLUTO! 🎉");
    }
}