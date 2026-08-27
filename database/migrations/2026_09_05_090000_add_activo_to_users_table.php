<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baja lógica de usuarios: `activo` (default true). Un usuario inactivo no puede
 * iniciar sesión ni operar la API, pero conserva su historial (asistencias que
 * tomó, justificaciones que gestionó). El borrado definitivo sigue existiendo,
 * pero solo cuando el usuario no tiene grupos ni registros dependientes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'activo')) {
                $table->boolean('activo')->default(true)->after('password');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'activo')) {
                $table->dropColumn('activo');
            }
        });
    }
};
