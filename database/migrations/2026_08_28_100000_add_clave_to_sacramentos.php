<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `clave` es el identificador estable de un sacramento (`bautismo`, `comunion`,
 * `confirmacion`). La lógica de ruta sacramental buscaba por `nombre` ('Bautismo',
 * 'Primera Comunión', 'Confirmación'), lo que se rompería si una parroquia los
 * renombra. Con `clave` el nombre pasa a ser solo una etiqueta visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sacramentos', 'clave')) {
            Schema::table('sacramentos', function (Blueprint $table) {
                $table->string('clave', 30)->nullable()->after('nombre')->index();
            });
        }

        $mapa = [
            'Bautismo' => 'bautismo',
            'Primera Comunión' => 'comunion',
            'Confirmación' => 'confirmacion',
        ];

        foreach ($mapa as $nombre => $clave) {
            DB::table('sacramentos')
                ->where('nombre', $nombre)
                ->whereNull('clave')
                ->update(['clave' => $clave]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sacramentos', 'clave')) {
            Schema::table('sacramentos', function (Blueprint $table) {
                $table->dropColumn('clave');
            });
        }
    }
};
