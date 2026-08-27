<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Si la parroquia inicial se creó con el placeholder 'parroquia-piloto' (versión
 * anterior de la migración seed), se renombra a la parroquia real. No-op en
 * instalaciones nuevas donde ya se creó con el nombre correcto.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('parroquias')
            ->where('slug', 'parroquia-piloto')
            ->update([
                'nombre' => 'Parroquia Sagrado Corazón de Jesús',
                'slug' => 'sagrado-corazon-de-jesus',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Irreversible por diseño (no tiene sentido volver al placeholder).
    }
};
