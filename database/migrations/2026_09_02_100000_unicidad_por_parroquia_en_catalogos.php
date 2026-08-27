<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `nombre` era único GLOBAL en requisitos / sacramentos / tipo_apoderados, lo que
 * impedía que dos parroquias tuvieran un requisito o sacramento con el mismo
 * nombre (p. ej. "Partida de Bautismo"). Se cambia a único POR PARROQUIA.
 */
return new class extends Migration
{
    private array $tablas = ['requisitos', 'sacramentos', 'tipo_apoderados'];

    public function up(): void
    {
        foreach ($this->tablas as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $table->dropUnique("{$tabla}_nombre_unique");
                $table->unique(['parroquia_id', 'nombre']);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tablas as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $table->dropUnique("{$tabla}_parroquia_id_nombre_unique");
                $table->unique('nombre', "{$tabla}_nombre_unique");
            });
        }
    }
};
