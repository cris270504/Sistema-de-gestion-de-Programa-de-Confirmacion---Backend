<?php

use App\Models\Confirmando;
use App\Models\Grupo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

// Smoke test: GET /confirmandos antes devolvía TODO sin límite (->get()); ahora
// pagina (->paginate()) y respeta ?per_page=.
it('pagina el listado de confirmandos en vez de devolver todo sin límite', function () {
    Permission::findOrCreate('ver confirmandos', 'api');
    Permission::findOrCreate('ver todas las asistencias', 'api'); // bypasea el filtro por grupo del catequista

    $admin = User::factory()->create();
    $admin->givePermissionTo(['ver confirmandos', 'ver todas las asistencias']);

    $grupo = Grupo::create([
        'nombre' => 'Grupo Test',
        'periodo' => '2026',
        'color' => '#2563eb',
        'procedencia' => 'sede',
    ]);

    Confirmando::factory()->count(3)->create(['grupo_id' => $grupo->id]);

    Passport::actingAs($admin);

    $response = $this->getJson('/api/confirmandos?per_page=2');

    $response->assertOk();
    $response->assertJsonStructure(['data', 'current_page', 'per_page', 'total', 'last_page']);

    expect($response->json('per_page'))->toBe(2);
    expect($response->json('total'))->toBe(3);
    expect(count($response->json('data')))->toBe(2);
});
