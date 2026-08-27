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
        // recrearlo para admitir 'no_cumplido'. Se eliminan TODOS los checks que
        // toquen la columna `estado` (por si producción lo nombró distinto al
        // aplicarlo a mano) y se añade el definitivo. En sqlite (tests) el enum es
        // solo varchar sin constraint, así que este bloque no aplica.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DO $$
                DECLARE
                    r record;
                BEGIN
                    FOR r IN
                        SELECT con.conname
                        FROM pg_constraint con
                        JOIN pg_class rel ON rel.oid = con.conrelid
                        WHERE rel.relname = 'justificaciones'
                          AND con.contype = 'c'
                          AND pg_get_constraintdef(con.oid) ILIKE '%estado%'
                    LOOP
                        EXECUTE format('ALTER TABLE justificaciones DROP CONSTRAINT %I', r.conname);
                    END LOOP;

                    ALTER TABLE justificaciones
                        ADD CONSTRAINT justificaciones_estado_check
                        CHECK (estado IN ('injustificado', 'pendiente', 'justificado', 'no_cumplido'));
                END $$;
            SQL);
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
