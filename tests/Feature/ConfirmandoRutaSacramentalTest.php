<?php

use App\Models\Sacramento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

// asignarRutaSacramental resuelve los sacramentos por `clave` (estable), no por
// `nombre`. Renombrar un sacramento no debe romper la cascada.

beforeEach(function () {
    $this->bautismo = Sacramento::create(['nombre' => 'Sacramento del Bautismo', 'clave' => 'bautismo']);
    $this->comunion = Sacramento::create(['nombre' => 'Eucaristía (1ra)', 'clave' => 'comunion']);
    $this->confirmacion = Sacramento::create(['nombre' => 'Confirmación', 'clave' => 'confirmacion']);

    $this->admin = catequistaCon(['crear confirmandos']);
});

it('si le falta el bautismo, quedan los 3 sacramentos pendientes', function () {
    Passport::actingAs($this->admin);

    $res = $this->postJson('/api/confirmandos', [
        'nombres' => 'Juan', 'apellidos' => 'Pérez',
        'sacramento_faltante_id' => $this->bautismo->id,
    ])->assertCreated();

    $id = $res->json('confirmando.id');

    $this->assertDatabaseHas('confirmando_sacramento', ['confirmando_id' => $id, 'sacramento_id' => $this->bautismo->id, 'estado' => 'pendiente']);
    $this->assertDatabaseHas('confirmando_sacramento', ['confirmando_id' => $id, 'sacramento_id' => $this->comunion->id, 'estado' => 'pendiente']);
    $this->assertDatabaseHas('confirmando_sacramento', ['confirmando_id' => $id, 'sacramento_id' => $this->confirmacion->id, 'estado' => 'pendiente']);
});

it('si solo le falta la confirmación, los anteriores quedan como recibidos', function () {
    Passport::actingAs($this->admin);

    $res = $this->postJson('/api/confirmandos', [
        'nombres' => 'Ana', 'apellidos' => 'Torres',
        'sacramento_faltante_id' => $this->confirmacion->id,
    ])->assertCreated();

    $id = $res->json('confirmando.id');

    $this->assertDatabaseHas('confirmando_sacramento', ['confirmando_id' => $id, 'sacramento_id' => $this->bautismo->id, 'estado' => 'recibido']);
    $this->assertDatabaseHas('confirmando_sacramento', ['confirmando_id' => $id, 'sacramento_id' => $this->comunion->id, 'estado' => 'recibido']);
    $this->assertDatabaseHas('confirmando_sacramento', ['confirmando_id' => $id, 'sacramento_id' => $this->confirmacion->id, 'estado' => 'pendiente']);
});
