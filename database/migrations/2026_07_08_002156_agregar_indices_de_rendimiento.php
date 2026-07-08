<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up()
{
    // 1. Índices para relaciones polimórficas y estados en Asistencias
    Schema::table('asistencia', function (Blueprint $table) {
        $table->index('estado');
        $table->index('reunion_id');
    });

    // 2. Índices para Confirmandos
    Schema::table('confirmandos', function (Blueprint $table) {
        $table->index('grupo_id');
        $table->index('estado');
    });

    // 3. Índices para Justificaciones
    Schema::table('justificaciones', function (Blueprint $table) {
        $table->index('asistencia_id');
        $table->index('estado');
    });
}

public function down()
{
    // Reversión de los índices en caso de rollback
    Schema::table('asistencia', function (Blueprint $table) {
        $table->dropIndex(['asistente_type', 'asistente_id']);
        $table->dropIndex(['estado']);
        $table->dropIndex(['reunion_id']);
    });

    Schema::table('confirmandos', function (Blueprint $table) {
        $table->dropIndex(['grupo_id']);
        $table->dropIndex(['estado']);
    });

    Schema::table('justificaciones', function (Blueprint $table) {
        $table->dropIndex(['asistencia_id']);
        $table->dropIndex(['estado']);
    });
}
};
