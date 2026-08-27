<?php

use App\Models\Confirmando;
use App\Models\Reunion;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\TenantConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function configValida(array $overrides = []): array
{
    // array_replace (shallow): un override de una clave la reemplaza por completo,
    // no la mezcla por índice (que rompería listas como tipos_reunion).
    return array_replace([
        'programa_inicio' => null,
        'programa_fin' => null,
        'dias_ventana_justificacion' => 21,
        'tipos_reunion' => ['Confirmandos', 'Catequistas', 'Apoderados'],
        'umbrales_alerta' => TenantConfig::DEFAULTS['umbrales_alerta'],
        'procedencias' => ['sede', 'caserio'],
        'branding' => ['nombre_publico' => 'SCJ', 'logo_url' => null, 'color_primario' => '#123456'],
    ], $overrides);
}

it('GET /parroquia/configuracion devuelve la config efectiva', function () {
    Passport::actingAs(catequistaCon(['ver dashboard']));

    $this->getJson('/api/parroquia/configuracion')
        ->assertOk()
        ->assertJsonPath('dias_ventana_justificacion', 21)
        ->assertJsonPath('branding.color_primario', '#2563eb');
});

it('PUT requiere el permiso administrar parroquia', function () {
    Passport::actingAs(catequistaCon(['ver dashboard']));

    $this->putJson('/api/parroquia/configuracion', configValida())->assertForbidden();
});

it('un usuario con administrar parroquia guarda la config y se invalida la caché', function () {
    Passport::actingAs(catequistaCon(['administrar parroquia']));

    $this->putJson('/api/parroquia/configuracion', configValida([
        'dias_ventana_justificacion' => 30,
        'branding' => ['color_primario' => '#ff0000'],
    ]))->assertOk()->assertJsonPath('configuracion.dias_ventana_justificacion', 30);

    expect(Tenant::config()['dias_ventana_justificacion'])->toBe(30);
    expect(Tenant::config()['branding']['color_primario'])->toBe('#ff0000');
});

it('rechaza programa_fin sin programa_inicio', function () {
    Passport::actingAs(catequistaCon(['administrar parroquia']));

    $this->putJson('/api/parroquia/configuracion', configValida([
        'programa_fin' => '2026-11-30',
    ]))->assertStatus(422);
});

it('acepta programa_inicio sin programa_fin (cierre incierto)', function () {
    Passport::actingAs(catequistaCon(['administrar parroquia']));

    $this->putJson('/api/parroquia/configuracion', configValida([
        'programa_inicio' => '2026-05-01',
    ]))->assertOk()->assertJsonPath('configuracion.programa_fin', null);
});

it('quitar Apoderados de tipos_reunion hace que crear una reunión de ese tipo dé 422', function () {
    $admin = catequistaCon(['administrar parroquia', 'crear cronograma']);
    Passport::actingAs($admin);

    $this->putJson('/api/parroquia/configuracion', configValida([
        'tipos_reunion' => ['Confirmandos', 'Catequistas'],
    ]))->assertOk();

    $this->postJson('/api/reuniones', [
        'nombre_tema' => 'Charla', 'fecha' => now()->addWeek()->toDateString(), 'tipo' => 'Apoderados',
    ])->assertStatus(422);

    $this->postJson('/api/reuniones', [
        'nombre_tema' => 'Clase', 'fecha' => now()->addWeek()->toDateString(), 'tipo' => 'Confirmandos',
    ])->assertCreated();
});

it('bajar el umbral alto_injustificadas cambia las alertas del dashboard', function () {
    Permission::findOrCreate('ver usuarios', 'api');
    $admin = catequistaCon(['administrar parroquia', 'ver dashboard', 'ver usuarios']);

    $grupo = grupoCon('G1');
    $conf = Confirmando::factory()->create(['grupo_id' => $grupo->id, 'estado' => 'en_preparacion']);

    // 3 faltas injustificadas NO consecutivas (asistió en medio) -> racha máx = 1,
    // solo cuenta el acumulado. Con umbral alto_injustificadas=4 no alerta; con 3 sí.
    $estados = ['falta injustificada', 'asistio', 'falta injustificada', 'asistio', 'falta injustificada'];
    foreach ($estados as $i => $estado) {
        $r = Reunion::create(['nombre_tema' => "R$i", 'fecha' => now()->subDays(20 - $i), 'tipo' => 'Confirmandos']);
        $conf->asistencias()->create(['reunion_id' => $r->id, 'estado' => $estado]);
    }

    Passport::actingAs($admin);

    $sinAlerta = collect($this->getJson('/api/dashboard/metricas')->json('alertas'))->firstWhere('id', $conf->id);
    expect($sinAlerta)->toBeNull();

    $this->putJson('/api/parroquia/configuracion', configValida([
        'umbrales_alerta' => ['alto_injustificadas' => 3] + TenantConfig::DEFAULTS['umbrales_alerta'],
    ]))->assertOk();

    $conAlerta = collect($this->getJson('/api/dashboard/metricas')->json('alertas'))->firstWhere('id', $conf->id);
    expect($conAlerta)->not->toBeNull();
    expect($conAlerta['nivel_riesgo'])->toBe('ALTO');
});
