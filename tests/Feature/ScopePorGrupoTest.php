<?php

use App\Models\Confirmando;
use App\Models\Reunion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// El catequista solo debe ver los confirmandos / grupos / filas de la matriz de
// asistencia de SUS grupos. El coordinador ve todo. (En sqlite no hay RLS, así que
// esto ejercita el filtro en PHP de los controladores.)

beforeEach(function () {
    Role::findOrCreate('coordinador', 'api');

    $this->grupoA = grupoCon('Grupo A');
    $this->grupoB = grupoCon('Grupo B');

    $this->confA = Confirmando::factory()->create(['grupo_id' => $this->grupoA->id, 'estado' => 'en_preparacion']);
    $this->confB = Confirmando::factory()->create(['grupo_id' => $this->grupoB->id, 'estado' => 'en_preparacion']);

    $this->catequista = catequistaCon(
        ['ver confirmandos', 'ver asistencias', 'ver grupos'],
        [$this->grupoA->id]
    );
});

it('GET /confirmandos: el catequista solo ve los de sus grupos', function () {
    Passport::actingAs($this->catequista);

    $ids = collect($this->getJson('/api/confirmandos')->json('data'))->pluck('id');

    expect($ids)->toContain($this->confA->id)->not->toContain($this->confB->id);
});

it('GET /grupos: el catequista solo ve sus grupos', function () {
    Passport::actingAs($this->catequista);

    $ids = collect($this->getJson('/api/grupos')->json())->pluck('id');

    expect($ids)->toContain($this->grupoA->id)->not->toContain($this->grupoB->id);
});

it('GET /asistencias/matriz: el catequista solo ve personas de sus grupos', function () {
    Reunion::create(['nombre_tema' => 'R1', 'fecha' => now()->subDay(), 'tipo' => 'Confirmandos']);

    Passport::actingAs($this->catequista);

    $ids = collect($this->getJson('/api/asistencias/matriz?tipo=Confirmandos')->json('personas'))->pluck('id');

    expect($ids)->toContain($this->confA->id)->not->toContain($this->confB->id);
});

it('el coordinador ve los confirmandos y grupos de todas las parroquias/grupos', function () {
    $coord = User::factory()->create();
    $coord->assignRole('coordinador');
    foreach (['ver confirmandos', 'ver asistencias', 'ver grupos'] as $p) {
        Permission::findOrCreate($p, 'api');
        $coord->givePermissionTo($p);
    }

    Passport::actingAs($coord);

    $confIds = collect($this->getJson('/api/confirmandos')->json('data'))->pluck('id');
    $grupoIds = collect($this->getJson('/api/grupos')->json())->pluck('id');

    expect($confIds)->toContain($this->confA->id)->toContain($this->confB->id);
    expect($grupoIds)->toContain($this->grupoA->id)->toContain($this->grupoB->id);
});
