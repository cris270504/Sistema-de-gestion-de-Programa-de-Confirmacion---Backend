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
        Schema::create('confirmando_apoderado', function (Blueprint $table) {
            $table->id();
            $table->foreignId('confirmando_id')->constrained('confirmandos')->onDelete('cascade');
            $table->foreignId('apoderado_id')->constrained('apoderados')->onDelete('cascade');
            $table->foreignId('tipo_apoderado_id')->constrained('tipo_apoderados')->onDelete('cascade');
            $table->unique(['confirmando_id', 'apoderado_id', 'tipo_apoderado_id'], 'confirmando_apoderado_tipo_unique');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('confirmando_apoderado');
    }
};
