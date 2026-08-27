<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La tabla `justificaciones` quedó desincronizada con el código: el controlador
 * usa la columna `fecha_acuerdo` y el estado `no_cumplido`, pero la migración
 * original (2026_05_18) no los incluía. Producción se ajustó a mano; esta
 * migración reconcilia cualquier base nueva (tests, otra parroquia) con guardas
 * para que sea idempotente donde ya se aplicó el cambio manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('justificaciones', 'fecha_acuerdo')) {
            Schema::table('justificaciones', function (Blueprint $table) {
                $table->date('fecha_acuerdo')->nullable()->after('descripcion');
            });
        }

        // El enum de Laravel se traduce a un CHECK constraint en Postgres; hay que
        // recrearlo para admitir 'no_cumplido'. En sqlite (tests) el enum es solo
        // varchar sin constraint, así que no aplica.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE justificaciones DROP CONSTRAINT IF EXISTS justificaciones_estado_check');
            DB::statement("ALTER TABLE justificaciones ADD CONSTRAINT justificaciones_estado_check CHECK (estado IN ('injustificado', 'pendiente', 'justificado', 'no_cumplido'))");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE justificaciones DROP CONSTRAINT IF EXISTS justificaciones_estado_check');
            DB::statement("ALTER TABLE justificaciones ADD CONSTRAINT justificaciones_estado_check CHECK (estado IN ('injustificado', 'pendiente', 'justificado'))");
        }

        if (Schema::hasColumn('justificaciones', 'fecha_acuerdo')) {
            Schema::table('justificaciones', function (Blueprint $table) {
                $table->dropColumn('fecha_acuerdo');
            });
        }
    }
};
