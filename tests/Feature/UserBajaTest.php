<?php

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

it('permite borrar un usuario sin grupos ni asistencias', function () {
    $limpio = User::factory()->create();
    Passport::actingAs($this->admin);

    $this->deleteJson("/api/users/{$limpio->id}")
        ->assertNoContent();

    expect(User::find($limpio->id))->toBeNull();
});
