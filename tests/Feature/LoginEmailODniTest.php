<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    // La migración de Passport se salta en testing, así que el personal access client
    // (necesario para $user->createToken() dentro del login) hay que crearlo aquí.
    Artisan::call('passport:client', [
        '--personal' => true,
        '--name' => 'Test Personal Access Client',
        '--no-interaction' => true,
    ]);

    $this->user = User::factory()->create([
        'dni' => '87654321',
        'email' => 'catequista@parroquia.com',
        'password' => Hash::make('clave-segura'),
    ]);
});

it('permite iniciar sesión con el DNI en el campo login', function () {
    $this->postJson('/api/login', [
        'login' => '87654321',
        'password' => 'clave-segura',
    ])->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'email', 'dni']]);
});

it('permite iniciar sesión con el email en el campo login', function () {
    $this->postJson('/api/login', [
        'login' => 'catequista@parroquia.com',
        'password' => 'clave-segura',
    ])->assertOk()->assertJsonPath('user.id', $this->user->id);
});

it('sigue aceptando el campo legado dni', function () {
    $this->postJson('/api/login', [
        'dni' => '87654321',
        'password' => 'clave-segura',
    ])->assertOk();
});

it('rechaza credenciales inválidas con 401', function () {
    $this->postJson('/api/login', [
        'login' => 'catequista@parroquia.com',
        'password' => 'incorrecta',
    ])->assertStatus(401);
});

it('revoca los tokens anteriores en cada login (una sola sesión activa)', function () {
    $creds = ['login' => 'catequista@parroquia.com', 'password' => 'clave-segura'];

    $this->postJson('/api/login', $creds)->assertOk();
    $this->postJson('/api/login', $creds)->assertOk();

    expect($this->user->tokens()->where('revoked', false)->count())->toBe(1);
});

it('el token de acceso expira (no vive un año)', function () {
    $this->postJson('/api/login', [
        'login' => 'catequista@parroquia.com', 'password' => 'clave-segura',
    ])->assertOk();

    $expira = $this->user->tokens()->latest('created_at')->first()->expires_at;

    expect($expira)->not->toBeNull();
    expect($expira->lessThan(now()->addDays(60)))->toBeTrue();
});
