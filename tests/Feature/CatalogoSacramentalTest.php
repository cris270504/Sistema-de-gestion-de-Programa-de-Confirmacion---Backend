<?php

use App\Models\Confirmando;
use App\Models\Parroquia;
use App\Models\Sacramento;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\SembrarCatalogoSacramental;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

it('siembra el catálogo estándar en una parroquia, aislado de las demás', function () {
    $pA = $this->parroquia;
    $pB = Parroquia::factory()->create();

    // La parroquia A ya tiene un sacramento propio.
    Sacramento::create(['nombre' => 'Bautismo A', 'clave' => 'bautismo']);

    app(SembrarCatalogoSacramental::class)->paraParroquia($pB->id);

    $clavesB = Tenant::runFor($pB->id, fn () => Sacramento::pluck('clave')->sort()->values()->all());
    expect($clavesB)->toBe(['bautismo', 'comunion', 'confirmacion']);

    $confirmacionB = Tenant::runFor($pB->id, fn () => Sacramento::where('clave', 'confirmacion')->with('requisitos')->first());
    expect($confirmacionB->requisitos)->toHaveCount(5);
    expect($confirmacionB->nombre)->toBe('Confirmación');

    // La parroquia A sigue con su único sacramento.
    $clavesA = Tenant::runFor($pA->id, fn () => Sacramento::pluck('nombre')->all());
    expect($clavesA)->toBe(['Bautismo A']);
});

it('es idempotente: no duplica si la parroquia ya tiene catálogo', function () {
    $pB = Parroquia::factory()->create();

    app(SembrarCatalogoSacramental::class)->paraParroquia($pB->id);
    app(SembrarCatalogoSacramental::class)->paraParroquia($pB->id);

    $total = Tenant::runFor($pB->id, fn () => Sacramento::count());
    expect($total)->toBe(3);
});

it('asignarRutaSacramental usa solo los sacramentos de la parroquia del confirmando', function () {
    $pA = $this->parroquia;
    $pB = Parroquia::factory()->create();

    app(SembrarCatalogoSacramental::class)->paraParroquia($pA->id);
    app(SembrarCatalogoSacramental::class)->paraParroquia($pB->id);

    $bautismoA = Tenant::runFor($pA->id, fn () => Sacramento::where('clave', 'bautismo')->first());

    $admin = catequistaCon(['crear confirmandos']); // pertenece a la parroquia A (contexto del beforeEach)
    Passport::actingAs($admin);

    $res = $this->postJson('/api/confirmandos', [
        'nombres' => 'Luis', 'apellidos' => 'Gómez',
        'sacramento_faltante_id' => $bautismoA->id,
    ])->assertCreated();

    $id = $res->json('confirmando.id');
    $sacramentosDelConfirmando = Tenant::runFor($pA->id, fn () => Confirmando::find($id)->sacramentos->pluck('parroquia_id')->unique()->all());

    expect($sacramentosDelConfirmando)->toBe([$pA->id]);
});
