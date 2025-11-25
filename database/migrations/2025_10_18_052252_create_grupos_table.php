<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('grupos', function (Blueprint $table) {
            $table->id();

            $table->string('nombre');
            $table->string('periodo');

            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('grupo_id')
                ->references('id')
                ->on('grupos')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // El nombre suele ser tabla_columna_foreign
            $table->dropForeign(['grupo_id']);
        });
        Schema::dropIfExists('grupos');
    }
};
