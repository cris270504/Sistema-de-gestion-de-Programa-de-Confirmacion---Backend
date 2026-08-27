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
    // Esta migración quedó 100% duplicada de 2026_07_08_002156_agregar_indices_de_rendimiento.php
    // (los mismos 6 índices, creados dos veces). En producción ya corrió y quedó registrada, así
    // que no se toca eso; para una base de datos limpia (ej. los tests con RefreshDatabase) la
    // dejamos como no-op para no reventar con "index already exists".
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
