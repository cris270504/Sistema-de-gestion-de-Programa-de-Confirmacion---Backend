<?php

use App\Models\Confirmando;
use App\Models\Parroquia;
use App\Models\Reunion;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// Aislamiento entre parroquias: un usuario de la parroquia A no ve NADA de la B.
// (En sqlite no hay RLS; esto ejercita el Global Scope + los filtros explícitos.)

beforeEach(function () {
    Role::findOrCreate('coordinador', 'api');
    foreach (['ver usuarios', 'ver confirmandos', 'ver cronograma', 'ver todas las asistencias'] as $p) {
        Permission::findOrCreate($p, 'api');
    }

    // Parroquia A = la piloto que crea el beforeEach global (contexto actual)
    $this->parroquiaA = $this->parroquia;
    $this->parroquiaB = Parroquia::factory()->create(['nombre' => 'Parroquia B']);

    $this->grupoA = grupoCon('Grupo A');
    $this->confA = Confirmando::factory()->create(['grupo_id' => $this->grupoA->id, 'estado' => 'en_preparacion']);
    $this->reunionA = Reunion::create(['nombre_tema' => 'Reunión A', 'fecha' => now()->addWeek(), 'tipo' => 'Confirmandos']);

    [$this->grupoB, $this->confB, $this->reunionB] = Tenant::runFor($this->parroquiaB->id, function () {
        $g = grupoCon('Grupo B');

        return [
            $g,
            Confirmando::factory()->create(['grupo_id' => $g->id, 'estado' => 'en_preparacion']),
            Reunion::create(['nombre_tema' => 'Reunión B', 'fecha' => now()->addWeek(), 'tipo' => 'Confirmandos']),
        ];
    });

    $this->userB = Tenant::runFor($this->parroquiaB->id, fn () => User::factory()->create(['name' => 'Coord B']));

    $this->coordA = User::factory()->create(['name' => 'Coord A']);
    $this->coordA->assignRole('coordinador');
    $this->coordA->givePermissionTo(['ver usuarios', 'ver confirmandos', 'ver cronograma', 'ver todas las asistencias']);

    Passport::actingAs($this->coordA);
});

it('GET /confirmandos solo devuelve los de la parroquia del usuario', function () {
    $ids = collect($this->getJson('/api/confirmandos')->json('data'))->pluck('id');

    expect($ids)->toContain($this->confA->id)->not->toContain($this->confB->id);
});

it('GET /grupos solo devuelve los de la parroquia del usuario', function () {
    $ids = collect($this->getJson('/api/grupos')->json())->pluck('id');

    expect($ids)->toContain($this->grupoA->id)->not->toContain($this->grupoB->id);
});

it('GET /reuniones solo devuelve las de la parroquia del usuario', function () {
    $ids = collect($this->getJson('/api/reuniones')->json())->pluck('id');

    expect($ids)->toContain($this->reunionA->id)->not->toContain($this->reunionB->id);
});

it('GET /users solo devuelve los de la parroquia del usuario', function () {
    $ids = collect($this->getJson('/api/users')->json())->pluck('id');

    expect($ids)->toContain($this->coordA->id)->not->toContain($this->userB->id);
});

it('GET /dashboard/metricas cuenta y alerta solo dentro de la parroquia', function () {
    $res = $this->getJson('/api/dashboard/metricas')->assertOk();

    expect($res->json('metricas.cant_confirmandos'))->toBe(1);
    expect($res->json('metricas.cant_grupos'))->toBe(1);
});

it('no se puede leer un confirmando de otra parroquia por id', function () {
    $this->getJson("/api/confirmandos/{$this->confB->id}")->assertNotFound();
});

it('el creating hook asigna la parroquia del usuario que crea, no una del payload', function () {
    $this->coordA->givePermissionTo(Permission::findOrCreate('crear confirmandos', 'api'));

    $res = $this->postJson('/api/confirmandos', [
        'nombres' => 'Nuevo', 'apellidos' => 'Confirmando',
        'parroquia_id' => $this->parroquiaB->id, // intento de inyección
    ])->assertCreated();

    $this->assertDatabaseHas('confirmandos', [
        'id' => $res->json('confirmando.id'),
        'parroquia_id' => $this->parroquiaA->id,
    ]);
});
