<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // En testing, RefreshDatabase corre TODAS las migraciones en cada test (sqlite en
        // memoria) — este comando no es necesario ahí (Passport::actingAs() en los tests no
        // pasa por OAuth real) y su prompt interno rompía la suite con
        // "Received Mockery...askQuestion(), but no expectations were specified" pese al
        // --no-interaction. Se salta solo en testing; en producción/desarrollo corre igual.
        if (app()->environment('testing')) {
            return;
        }

        // Esto ejecutará el comando internamente apuntando a Supabase
        Artisan::call('passport:client', [
            '--personal' => true,
            '--name' => 'Confirmacion Personal Access Client',
            '--no-interaction' => true,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No es estrictamente necesario para este fix temporal
    }
};