<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('justificaciones', function (Blueprint $table) {
            $table->id();
            // Relación directa con la asistencia que se está corrigiendo
            $table->foreignId('asistencia_id')->constrained('asistencia')->onDelete('cascade');

            // Datos del proceso de justificación
            $table->string('motivo'); // Ej: "Enfermedad", "Cita médica", "Viaje familiar"
            $table->text('descripcion')->nullable(); // Acción que realizará para justificar su falta. Por ejemplo: El joven apoyará leyendo una lectura en la misa del 24/05
            // Cambia tu línea de estado por esta:
            $table->enum('estado', ['injustificado', 'pendiente', 'justificado'])->default('injustificado'); // Por defecto debe ser injustificado. Si el padre se acerca a conversar y se acuerda cómo justificará la falta, se llena el campo descripción y el estado cambia a pendiente. Cuando se realiza la acción manualmente se cambia el estado a justificado
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('justificaciones');
    }
};
