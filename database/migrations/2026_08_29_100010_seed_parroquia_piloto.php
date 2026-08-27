<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Crea la parroquia inicial (la única que existía antes del multi-tenant). Todos
 * los datos actuales se le asignan en add_parroquia_id_to_core_tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('parroquias')->count() === 0) {
            DB::table('parroquias')->insert([
                'nombre' => 'Parroquia Sagrado Corazón de Jesús',
                'slug' => 'sagrado-corazon-de-jesus',
                'activa' => true,
                'zona_horaria' => 'America/Lima',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('parroquias')->whereIn('slug', ['sagrado-corazon-de-jesus', 'parroquia-piloto'])->delete();
    }
};
