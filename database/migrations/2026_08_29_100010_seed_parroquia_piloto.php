<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Crea la parroquia piloto (la única que existía antes del multi-tenant). Todos
 * los datos actuales se le asignan en la migración add_parroquia_id_to_core_tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('parroquias')->where('slug', 'parroquia-piloto')->doesntExist()) {
            DB::table('parroquias')->insert([
                'nombre' => 'Parroquia Piloto',
                'slug' => 'parroquia-piloto',
                'activa' => true,
                'zona_horaria' => 'America/Lima',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('parroquias')->where('slug', 'parroquia-piloto')->delete();
    }
};
