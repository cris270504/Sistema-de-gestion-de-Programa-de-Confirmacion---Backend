<?php

use App\Models\Confirmando;
use App\Models\Reunion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

// El store de asistencias pasó de un updateOrCreate() por fila (2N queries) a
// 1 SELECT + INSERT masivo de nuevas + UPDATE de existentes. Este test fija el
// comportamiento: crea las que faltan, actualiza las que ya estaban, sin duplicar.
it('crea y actualiza asistencias en lote sin duplicar', function () {
    Permission::findOrCreate('guardar asistencias', 'api');
    Permission::findOrCreate('ver todas las asistencias', 'api');

    $gestor = User::factory()->create();
    $gestor->givePermissionTo(['guardar asistencias', 'ver todas las asistencias']);

    $reunion = Reunion::create([
        'nombre_tema' => 'Reunión 1',
        'fecha' => now()->subDay(),
        'tipo' => 'Confirmandos',
    ]);

    $a = Confirmando::factory()->create(['estado' => 'en_preparacion']);
    $b = Confirmando::factory()->create(['estado' => 'en_preparacion']);

    Passport::actingAs($gestor);

    // 1ª toma: ambas son altas.
    $this->postJson("/api/reuniones/{$reunion->id}/asistencias", [
        'asistencias' => [
            ['asistente_id' => $a->id, 'asistente_type' => Confirmando::class, 'estado' => 'asistio'],
            ['asistente_id' => $b->id, 'asistente_type' => Confirmando::class, 'estado' => 'falta injustificada'],
        ],
    ])->assertOk();

    expect($a->asistencias()->count())->toBe(1);
    expect($b->asistencias()->where('estado', 'falta injustificada')->exists())->toBeTrue();

    // 2ª toma: corrige el estado de B, no duplica.
    $this->postJson("/api/reuniones/{$reunion->id}/asistencias", [
        'asistencias' => [
            ['asistente_id' => $b->id, 'asistente_type' => Confirmando::class, 'estado' => 'asistio', 'nota' => 'llegó tarde justificado'],
        ],
    ])->assertOk();

    expect($b->asistencias()->count())->toBe(1);
    expect($b->asistencias()->first()->estado)->toBe('asistio');
    expect($b->asistencias()->first()->nota)->toBe('llegó tarde justificado');
});
