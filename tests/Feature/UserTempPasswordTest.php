<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// Regresión: UserController@store hardcodeaba la contraseña de todo usuario nuevo
// como '123456789' (una constante conocida, no devuelta por la API) en vez de
// generar una aleatoria y devolverla en la respuesta.
it('genera una contraseña temporal aleatoria (no la constante vieja) y la devuelve en la respuesta', function () {
    Permission::findOrCreate('crear usuarios', 'api');
    Role::findOrCreate('catequista', 'api'); // store() valida roles.*: exists:roles,name

    $admin = User::factory()->create();
    $admin->givePermissionTo('crear usuarios');
    Passport::actingAs($admin);

    $response = $this->postJson('/api/users', [
        'name' => 'Nuevo Catequista',
        'dni' => '87654321',
        'celular' => '987654321',
        'email' => 'nuevo.catequista@test.com',
        'fecha_nacimiento' => '1995-01-01',
        'roles' => ['catequista'],
    ]);

    $response->assertCreated();

    $tempPassword = $response->json('temp_password');

    expect($tempPassword)->not->toBeNull();
    expect($tempPassword)->not->toBe('123456789');
    expect(strlen($tempPassword))->toBe(10);

    // La contraseña generada de verdad quedó hasheada como la contraseña real del usuario.
    $creado = User::where('email', 'nuevo.catequista@test.com')->firstOrFail();
    expect(Hash::check($tempPassword, $creado->password))->toBeTrue();
});
