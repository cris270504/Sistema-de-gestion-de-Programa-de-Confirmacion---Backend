<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Log de errores JS no capturados que reporta el frontend (Vue). Solo lectura
 * para privilegiados (coordinador/super-admin) — es información de diagnóstico,
 * no algo que un catequista necesite ver de otros usuarios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frontend_error_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('message');
            $table->text('stack')->nullable();
            $table->string('url')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Reutiliza app_current_user_id()/app_is_privileged(), ya definidas en
        // 2026_08_11_120000_enable_row_level_security.php.
        DB::unprepared(<<<'SQL'
            ALTER TABLE frontend_error_logs ENABLE ROW LEVEL SECURITY;
            ALTER TABLE frontend_error_logs FORCE ROW LEVEL SECURITY;

            -- Solo privilegiados pueden leer/borrar logs. Nadie los actualiza (append-only,
            -- sin política de UPDATE => denegado por defecto bajo FORCE ROW LEVEL SECURITY).
            CREATE POLICY frontend_error_logs_select ON frontend_error_logs FOR SELECT
                USING (app_is_privileged());

            -- Cualquier usuario autenticado puede insertar SU PROPIO error (no puede
            -- reportar un error a nombre de otro user_id).
            CREATE POLICY frontend_error_logs_insert ON frontend_error_logs FOR INSERT
                WITH CHECK (app_is_privileged() OR user_id = app_current_user_id());

            CREATE POLICY frontend_error_logs_delete ON frontend_error_logs FOR DELETE
                USING (app_is_privileged());
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP POLICY IF EXISTS frontend_error_logs_delete ON frontend_error_logs;
                DROP POLICY IF EXISTS frontend_error_logs_insert ON frontend_error_logs;
                DROP POLICY IF EXISTS frontend_error_logs_select ON frontend_error_logs;
            SQL);
        }

        Schema::dropIfExists('frontend_error_logs');
    }
};
