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