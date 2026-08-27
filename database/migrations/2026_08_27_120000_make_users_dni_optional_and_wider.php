<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El DNI de users era char(8) NOT NULL (formato peruano). Para soportar otras
 * parroquias/países se vuelve opcional y más ancho; el identificador de acceso
 * garantizado pasa a ser el email (el login acepta correo o DNI).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('dni', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('dni', 8)->nullable(false)->change();
        });
    }
};
