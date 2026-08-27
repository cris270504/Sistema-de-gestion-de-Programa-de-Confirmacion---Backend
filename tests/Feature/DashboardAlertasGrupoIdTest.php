<?php

use App\Models\Confirmando;
use App\Models\Grupo;
use App\Models\Reunion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

// Regresión: el array de alertas de /dashboard/metricas no incluía grupo_id (solo el
// nombre del grupo), lo que rompía el filtro por grupo del catequista en el frontend.
it('incluye grupo_id en cada alerta del dashboard', function () {
    Permission::findOrCreate('ver usuarios', 'api');
    $admin = User::factory()->create();
    $admin->givePermissionTo('ver usuarios');

    $grupo = Grupo::create([
        'nombre' => 'Grupo Test',
        'periodo' => '2026',
        'color' => '#2563eb',
        'procedencia' => 'sede',
    ]);

    $confirmando = Confirmando::factory()->create([
        'grupo_id' => $grupo->id,
        'estado' => 'en_preparacion',
    ]);

    $reunion = Reunion::create([
        'nombre_tema' => 'Reunión 1',
        'fecha' => now()->subDays(10),
        'tipo' => 'Confirmandos',
    ]);

    // 4 faltas injustificadas dispara la alerta (total_faltas_injustificadas >= 4).
    for ($i = 0; $i < 4; $i++) {
        $confirmando->asistencias()->create([
            'reunion_id' => $reunion->id,
            'estado' => 'falta injustificada',
        ]);
    }

    Passport::actingAs($admin);

    $response = $this->getJson('/api/dashboard/metricas');

    $response->assertOk();

    $alerta = collect($response->json('alertas'))->firstWhere('id', $confirmando->id);

    expect($alerta)->not->toBeNull();
    expect($alerta['grupo_id'])->toBe($grupo->id);
    expect($alerta['grupo'])->toBe('Grupo Test');
});
