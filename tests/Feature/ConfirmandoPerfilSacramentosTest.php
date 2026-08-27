<?php

use App\Models\Confirmando;
use App\Models\Sacramento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

// Regresión: obtenerPerfilCompleto leía $confirmando->falta_bautizo / falta_comunion,
// atributos que no existen en el modelo -> siempre devolvía "Ninguno (Tiene todos)".
// Ahora deriva los faltantes del pivote confirmando_sacramento (estado 'pendiente').
it('reporta los sacramentos previos pendientes en el perfil del confirmando', function () {
    Permission::findOrCreate('ver confirmandos', 'api');
    $user = User::factory()->create();
    $user->givePermissionTo('ver confirmandos');

    $bautismo = Sacramento::create(['nombre' => 'Bautismo']);
    $comunion = Sacramento::create(['nombre' => 'Primera Comunión']);
    $confirmacion = Sacramento::create(['nombre' => 'Confirmación']);

    $confirmando = Confirmando::factory()->create(['estado' => 'en_preparacion']);
    $confirmando->sacramentos()->sync([
        $bautismo->id => ['estado' => 'recibido'],
        $comunion->id => ['estado' => 'pendiente'],
        $confirmacion->id => ['estado' => 'pendiente'],
    ]);

    Passport::actingAs($user);

    $response = $this->getJson("/api/confirmandos/{$confirmando->id}/perfil");

    $response->assertOk();
    expect($response->json('joven.sacramentos_faltantes'))->toBe('Primera Comunión');
});

it('reporta "Ninguno" cuando solo falta la Confirmación', function () {
    Permission::findOrCreate('ver confirmandos', 'api');
    $user = User::factory()->create();
    $user->givePermissionTo('ver confirmandos');

    $bautismo = Sacramento::create(['nombre' => 'Bautismo']);
    $comunion = Sacramento::create(['nombre' => 'Primera Comunión']);
    $confirmacion = Sacramento::create(['nombre' => 'Confirmación']);

    $confirmando = Confirmando::factory()->create(['estado' => 'en_preparacion']);
    $confirmando->sacramentos()->sync([
        $bautismo->id => ['estado' => 'recibido'],
        $comunion->id => ['estado' => 'recibido'],
        $confirmacion->id => ['estado' => 'pendiente'],
    ]);

    Passport::actingAs($user);

    $response = $this->getJson("/api/confirmandos/{$confirmando->id}/perfil");

    $response->assertOk();
    expect($response->json('joven.sacramentos_faltantes'))->toBe('Ninguno (Tiene todos)');
});
