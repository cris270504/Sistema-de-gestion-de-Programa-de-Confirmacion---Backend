<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Añade `parroquia_id` (FK NOT NULL) a las tablas raíz. Las pivote y
 * asistencia/justificaciones heredan la parroquia por su relación, no llevan columna.
 *
 * Proceso por tabla: añadir nullable -> backfill a la parroquia piloto -> NOT NULL
 * -> FK + índice.
 */
return new class extends Migration
{
    private array $tablas = [
        'users',
        'grupos',
        'confirmandos',
        'apoderados',
        'reunions',
        'sacramentos',
        'requisitos',
        'tipo_apoderados',
        'frontend_error_logs',
    ];

    public function up(): void
    {
        $pilotoId = DB::table('parroquias')->orderBy('id')->value('id');

        foreach ($this->tablas as $tabla) {
            if (Schema::hasColumn($tabla, 'parroquia_id')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) {
                $table->unsignedBigInteger('parroquia_id')->nullable()->after('id');
            });

            DB::table($tabla)->whereNull('parroquia_id')->update(['parroquia_id' => $pilotoId]);

            Schema::table($tabla, function (Blueprint $table) {
                $table->unsignedBigInteger('parroquia_id')->nullable(false)->change();
            });

            Schema::table($tabla, function (Blueprint $table) {
                $table->foreign('parroquia_id')->references('id')->on('parroquias')->restrictOnDelete();
                $table->index('parroquia_id');
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tablas) as $tabla) {
            if (! Schema::hasColumn($tabla, 'parroquia_id')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $table->dropForeign($tabla.'_parroquia_id_foreign');
                $table->dropIndex($tabla.'_parroquia_id_index');
                $table->dropColumn('parroquia_id');
            });
        }
    }
};
