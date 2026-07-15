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
        // Índices existentes (mantener)
        Schema::table('asistencia', function (Blueprint $table) {
            $table->index('estado');
            $table->index('reunion_id');

            // NUEVO: Índice compuesto para relaciones polimórficas
            $table->index(['asistente_type', 'asistente_id']);

            // NUEVO: Índice compuesto para consultas frecuentes
            $table->index(['reunion_id', 'estado']);
            $table->index(['asistente_type', 'asistente_id', 'estado']);
        });

        Schema::table('confirmandos', function (Blueprint $table) {
            $table->index('grupo_id');
            $table->index('estado');

            // NUEVO: Índice compuesto para filtrado por grupo y estado
            $table->index(['grupo_id', 'estado']);
        });

        Schema::table('justificaciones', function (Blueprint $table) {
            $table->index('asistencia_id');
            $table->index('estado');

            // NUEVO: Índice compuesto para consultas de estado
            $table->index(['asistencia_id', 'estado']);
        });

        // NUEVO: Índices para tabla reuniones
        Schema::table('reunions', function (Blueprint $table) {
            $table->index(['tipo', 'fecha']);
            $table->index('fecha');
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
