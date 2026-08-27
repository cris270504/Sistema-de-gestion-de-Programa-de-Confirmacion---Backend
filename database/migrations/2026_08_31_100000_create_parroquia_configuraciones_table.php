<?php

use App\Tenancy\TenantConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración por parroquia (1:1). Los valores que antes estaban fijos en el
 * código: duración del programa, ventana de justificación, umbrales de alerta,
 * tipos de reunión activos, procedencias de grupo, y branding (nombre, logo, color).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parroquia_configuraciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parroquia_id')->unique()->constrained('parroquias')->cascadeOnDelete();

            $table->date('programa_inicio')->nullable();
            $table->date('programa_fin')->nullable(); // la fecha de cierre a veces es incierta

            $table->unsignedSmallInteger('dias_ventana_justificacion')->default(21);
            $table->json('tipos_reunion');
            $table->json('umbrales_alerta');
            $table->json('procedencias');
            $table->json('branding');

            $table->timestamps();
        });

        // Fila por defecto para las parroquias que ya existen.
        $d = TenantConfig::DEFAULTS;
        foreach (DB::table('parroquias')->pluck('id') as $parroquiaId) {
            DB::table('parroquia_configuraciones')->insert([
                'parroquia_id' => $parroquiaId,
                'programa_inicio' => null,
                'programa_fin' => null,
                'dias_ventana_justificacion' => $d['dias_ventana_justificacion'],
                'tipos_reunion' => json_encode($d['tipos_reunion']),
                'umbrales_alerta' => json_encode($d['umbrales_alerta']),
                'procedencias' => json_encode($d['procedencias']),
                'branding' => json_encode($d['branding']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE parroquia_configuraciones ENABLE ROW LEVEL SECURITY;
                ALTER TABLE parroquia_configuraciones FORCE ROW LEVEL SECURITY;
                CREATE POLICY parroquia_configuraciones_all ON parroquia_configuraciones
                    FOR ALL USING (true) WITH CHECK (true);
                CREATE POLICY parroquia_configuraciones_parroquia ON parroquia_configuraciones AS RESTRICTIVE FOR ALL
                    USING (app_parroquia_ok(parroquia_id))
                    WITH CHECK (app_parroquia_ok(parroquia_id));
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP POLICY IF EXISTS parroquia_configuraciones_parroquia ON parroquia_configuraciones;
                DROP POLICY IF EXISTS parroquia_configuraciones_all ON parroquia_configuraciones;
            SQL);
        }

        Schema::dropIfExists('parroquia_configuraciones');
    }
};
