<?php

use App\Models\Asistencia;
use App\Models\Reunion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', [
        '--personal' => true,
        '--name' => 'Test Personal Access Client',
        '--no-interaction' => true,
    ]);

    Permission::findOrCreate('editar usuarios', 'api');
    Permission::findOrCreate('eliminar usuarios', 'api');

    $this->admin = User::factory()->create();
    $this->admin->givePermissionTo(['editar usuarios', 'eliminar usuarios']);
});

it('desactiva y reactiva una cuenta vía el endpoint de estado', function () {
    $otro = User::factory()->create(['activo' => true]);
    Passport::actingAs($this->admin);

    $this->patchJson("/api/users/{$otro->id}/estado", ['activo' => false])
        ->assertOk()
        ->assertJsonPath('user.activo', false);

    expect($otro->fresh()->activo)->toBeFalse();

    $this->patchJson("/api/users/{$otro->id}/estado", ['activo' => true])
        ->assertOk();
    expect($otro->fresh()->activo)->toBeTrue();
});

it('no deja que un usuario cambie el estado de su propia cuenta', function () {
    Passport::actingAs($this->admin);

    $this->patchJson("/api/users/{$this->admin->id}/estado", ['activo' => false])
        ->assertStatus(422);

    expect($this->admin->fresh()->activo)->toBeTrue();
});

it('impide iniciar sesión a una cuenta desactivada', function () {
    User::factory()->create([
        'email' => 'inactivo@parroquia.com',
        'password' => Hash::make('clave-segura'),
        'activo' => false,
    ]);

    $this->postJson('/api/login', [
        'login' => 'inactivo@parroquia.com',
        'password' => 'clave-segura',
    ])->assertStatus(403);
});

it('bloquea el borrado de un usuario con grupos asignados', function () {
    $catequista = User::factory()->create();
    $grupo = grupoCon('Grupo San Juan');
    $catequista->grupos()->attach($grupo->id);

    Passport::actingAs($this->admin);

    $this->deleteJson("/api/users/{$catequista->id}")
        ->assertStatus(422);

    expect(User::find($catequista->id))->not->toBeNull();
});

it('permite borrar un usuario sin grupos', function () {
    $limpio = User::factory()->create();
    Passport::actingAs($this->admin);

    $this->deleteJson("/api/users/{$limpio->id}")
        ->assertNoContent();

    expect(User::find($limpio->id))->toBeNull();
});

it('borra un usuario sin grupos aunque tenga historial de asistencia (y lo limpia)', function () {
    $catequista = User::factory()->create();
    $reunion = Reunion::create([
        'nombre_tema' => 'Reunión de prueba',
        'fecha' => now()->toDateString(),
        'tipo' => 'Catequistas',
    ]);
    $asistencia = Asistencia::create([
        'reunion_id' => $reunion->id,
        'estado' => 'asistio',
        'asistente_id' => $catequista->id,
        'asistente_type' => $catequista->getMorphClass(),
    ]);

    Passport::actingAs($this->admin);

    $this->deleteJson("/api/users/{$catequista->id}")
        ->assertNoContent();

    expect(User::find($catequista->id))->toBeNull();
    expect(Asistencia::find($asistencia->id))->toBeNull();
});
