<?php

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

/**
 * Fase 1 migración Supabase: el guard `supabase` valida el JWT de Supabase Auth y
 * resuelve el User por `users.auth_id`.
 *
 * Estos tests ejercen la lógica del guard (firma, exp, aud, resolución de usuario,
 * cuenta activa) por la vía HS256. La vía ES256/JWKS —la que usa Supabase hoy— se
 * verificó de forma manual contra el stack local (`supabase/spike/` y notas de la
 * Fase 1); un test con JWKS mockeado requiere generar claves EC, que el PHP de
 * Windows no siempre permite en CI local.
 */
beforeEach(function () {
    $this->secret = 'test-hs256-secret-de-al-menos-32-caracteres!!';
    config()->set('services.supabase.url', null);              // desactiva el intento JWKS
    config()->set('services.supabase.jwt_secret', $this->secret);
    cache()->forget('supabase:jwks');
});

function supabaseToken(array $overrides, string $secret): string
{
    return JWT::encode(array_merge([
        'aud' => 'authenticated',
        'exp' => time() + 3600,
        'iat' => time(),
        'role' => 'authenticated',
    ], $overrides), $secret, 'HS256');
}

it('autentica una petición con un JWT de Supabase válido', function () {
    $user = User::factory()->create(['auth_id' => (string) Str::uuid()]);

    $this->withHeader('Authorization', 'Bearer '.supabaseToken(['sub' => $user->auth_id], $this->secret))
        ->getJson('/api/get-user')
        ->assertOk()
        ->assertJsonPath('id', $user->id);
});

it('rechaza un token con firma inválida', function () {
    $user = User::factory()->create(['auth_id' => (string) Str::uuid()]);

    $this->withHeader('Authorization', 'Bearer '.supabaseToken(['sub' => $user->auth_id], 'otro-secreto-distinto-de-32-caracteres!!!'))
        ->getJson('/api/get-user')
        ->assertUnauthorized();
});

it('rechaza un token sin fila en users.auth_id', function () {
    $this->withHeader('Authorization', 'Bearer '.supabaseToken(['sub' => (string) Str::uuid()], $this->secret))
        ->getJson('/api/get-user')
        ->assertUnauthorized();
});

it('rechaza un token expirado', function () {
    $user = User::factory()->create(['auth_id' => (string) Str::uuid()]);

    $this->withHeader('Authorization', 'Bearer '.supabaseToken(['sub' => $user->auth_id, 'exp' => time() - 10], $this->secret))
        ->getJson('/api/get-user')
        ->assertUnauthorized();
});

it('rechaza un token cuyo aud no es authenticated', function () {
    $user = User::factory()->create(['auth_id' => (string) Str::uuid()]);

    $this->withHeader('Authorization', 'Bearer '.supabaseToken(['sub' => $user->auth_id, 'aud' => 'anon'], $this->secret))
        ->getJson('/api/get-user')
        ->assertUnauthorized();
});

it('rechaza una cuenta desactivada', function () {
    $user = User::factory()->create(['auth_id' => (string) Str::uuid(), 'activo' => false]);

    $this->withHeader('Authorization', 'Bearer '.supabaseToken(['sub' => $user->auth_id], $this->secret))
        ->getJson('/api/get-user')
        ->assertUnauthorized();
});

it('sigue aceptando el guard api (Passport) durante la transición', function () {
    $user = User::factory()->create(['auth_id' => (string) Str::uuid()]);

    Passport::actingAs($user);

    $this->getJson('/api/get-user')->assertOk()->assertJsonPath('id', $user->id);
});
