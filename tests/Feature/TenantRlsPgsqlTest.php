<?php

use App\Models\Confirmando;
use App\Models\Parroquia;
use App\Tenancy\Facades\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// RLS de parroquia a nivel Postgres: verifica que una consulta CRUDA (saltándose
// el Global Scope de Eloquent) no puede leer filas de otra parroquia cuando hay
// contexto de parroquia fijado en la sesión. Solo corre si la suite apunta a pgsql.
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('RLS de parroquia solo aplica en pgsql.');
    }
});

it('una consulta cruda no ve confirmandos de otra parroquia con el contexto fijado', function () {
    $pA = $this->parroquia;
    $pB = Parroquia::factory()->create();

    $confA = Confirmando::factory()->create(['grupo_id' => grupoCon('A')->id, 'estado' => 'en_preparacion']);
    $confB = Tenant::runFor($pB->id, fn () => Confirmando::factory()->create([
        'grupo_id' => grupoCon('B')->id, 'estado' => 'en_preparacion',
    ]));

    // Fijamos el contexto de parroquia en la sesión Postgres como lo haría el middleware.
    DB::statement("SELECT set_config('app.current_user_privileged', 'true', false)");
    DB::statement('SELECT set_config(?, ?, false)', ['app.current_parroquia_id', (string) $pA->id]);

    $ids = collect(DB::select('SELECT id FROM confirmandos'))->pluck('id');

    expect($ids)->toContain($confA->id)->not->toContain($confB->id);

    // Sin contexto de parroquia, se ven ambos.
    DB::statement("SELECT set_config('app.current_parroquia_id', '', false)");
    $todos = collect(DB::select('SELECT id FROM confirmandos'))->pluck('id');
    expect($todos)->toContain($confA->id)->toContain($confB->id);
});
