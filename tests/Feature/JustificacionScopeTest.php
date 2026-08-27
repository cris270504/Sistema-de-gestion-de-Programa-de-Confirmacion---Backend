<?php

use App\Models\Confirmando;
use App\Models\Grupo;
use App\Models\Reunion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function grupoCon(string $nombre): Grupo
{
    return Grupo::create([
        'nombre' => $nombre,
        'periodo' => '2026',
        'color' => '#2563eb',
        'procedencia' => 'sede',
    ]);
}

function faltaInjustificada(Confirmando $c): void
{
    $reunion = Reunion::create([
        'nombre_tema' => 'Reunión',
        'fecha' => now()->subDays(5),
        'tipo' => 'Confirmandos',
    ]);
    $c->asistencias()->create(['reunion_id' => $reunion->id, 'estado' => 'falta injustificada']);
}

beforeEach(function () {
    Permission::findOrCreate('ver asistencias', 'api');
    Role::findOrCreate('catequista', 'api');
    Role::findOrCreate('coordinador', 'api');

    $this->grupoA = grupoCon('Grupo A');
    $this->grupoB = grupoCon('Grupo B');

    $this->confA = Confirmando::factory()->create(['grupo_id' => $this->grupoA->id, 'estado' => 'en_preparacion']);
    $this->confB = Confirmando::factory()->create(['grupo_id' => $this->grupoB->id, 'estado' => 'en_preparacion']);
    faltaInjustificada($this->confA);
    faltaInjustificada($this->confB);

    $this->catequista = User::factory()->create();
    $this->catequista->assignRole('catequista');
    $this->catequista->givePermissionTo('ver asistencias');
    $this->catequista->grupos()->attach($this->grupoA->id);
});

it('el catequista solo ve las justificaciones de sus grupos', function () {
    Passport::actingAs($this->catequista);

    $ids = collect($this->getJson('/api/justificaciones')->json())->pluck('confirmando_id');

    expect($ids)->toContain($this->confA->id)
        ->not->toContain($this->confB->id);
});

it('el coordinador ve las justificaciones de todos los grupos', function () {
    $coord = User::factory()->create();
    $coord->assignRole('coordinador');
    $coord->givePermissionTo('ver asistencias');

    Passport::actingAs($coord);

    $ids = collect($this->getJson('/api/justificaciones')->json())->pluck('confirmando_id');

    expect($ids)->toContain($this->confA->id)->toContain($this->confB->id);
});

it('el catequista no puede registrar un acuerdo para una falta de otro grupo', function () {
    Passport::actingAs($this->catequista);

    $asistenciaB = $this->confB->asistencias()->first();

    $this->postJson('/api/justificaciones/acuerdo', [
        'asistencia_id' => $asistenciaB->id,
        'motivo' => 'Test',
        'descripcion' => 'Test',
        'fecha_acuerdo' => now()->toDateString(),
    ])->assertNotFound();
});

it('el catequista sí puede registrar un acuerdo para una falta de su grupo', function () {
    Passport::actingAs($this->catequista);

    $asistenciaA = $this->confA->asistencias()->first();

    $this->postJson('/api/justificaciones/acuerdo', [
        'asistencia_id' => $asistenciaA->id,
        'motivo' => 'Enfermedad',
        'descripcion' => 'Trae certificado médico',
        'fecha_acuerdo' => now()->toDateString(),
    ])->assertOk();

    $this->assertDatabaseHas('justificaciones', [
        'asistencia_id' => $asistenciaA->id,
        'estado' => 'pendiente',
    ]);
});
