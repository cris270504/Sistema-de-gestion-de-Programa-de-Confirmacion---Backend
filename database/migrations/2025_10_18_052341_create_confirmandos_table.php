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
        Schema::create('confirmandos', function (Blueprint $table) {
            $table->id();

            $table->string('nombres');
            $table->string('apellidos');
            $table->string('celular',9)->nullable();
            $table->date('fecha_nacimiento');
            $table->foreignId('grupo_id')->nullable()->constrained('grupos')->onDelete('set null');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('confirmandos');
    }
};
