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
    Schema::table('asistencia', function (Blueprint $table) {
        // Asumo que el índice polimórfico ya existe (como vimos antes), 
        // así que solo agregamos los compuestos de estado
        $table->index(['reunion_id', 'estado']);  
        $table->index(['asistente_type', 'asistente_id', 'estado']);  
    });  
  
    Schema::table('confirmandos', function (Blueprint $table) {  
        $table->index(['grupo_id', 'estado']);  
    });  
  
    Schema::table('justificaciones', function (Blueprint $table) {  
        $table->index(['asistencia_id', 'estado']);  
    });  
      
    // NOMBRE CORREGIDO: reunions
    Schema::table('reunions', function (Blueprint $table) {  
        $table->index(['tipo', 'fecha']);  
        $table->index('fecha');  
    }); 
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
