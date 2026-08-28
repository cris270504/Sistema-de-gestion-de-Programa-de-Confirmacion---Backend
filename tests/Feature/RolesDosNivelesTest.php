<?php

use App\Models\Confirmando;
use App\Models\Parroquia;
use App\Models\Sacramento;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\TenantConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function proveedor(): User
{
    Role::findOrCreate('proveedor', 'api');
    $u = User::factory()->create();
    $u->assignRole('proveedor');

    return $u;
}

it('el proveedor ve datos de todas las parroquias', function () {
    $pA = $this->parroquia;
    $pB = Parroquia::factory()->create();

    Permission::findOrCreate('ver confirmandos', 'api');

    $confA = Confirmando::factory()->create(['grupo_id' => grupoCon('A')->id, 'estado' => 'en_preparacion']);
    $confB = Tenant::runFor($pB->id, fn () => Confirmando::factory()->create([
        'grupo_id' => grupoCon('B')->id, 'estado' => 'en_preparacion',
    ]));

    Passport::actingAs(proveedor());

    $ids = collect($this->getJson('/api/confirmandos')->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($confA->id)->toContain($confB->id);
});

function adminDeParroquia(array $permisos = []): User
{
    Role::findOrCreate('super-admin', 'api');
    $u = User::factory()->create();
    $u->assignRole('super-admin');
    foreach ($permisos as $p) {
        Permission::findOrCreate($p, 'api');
        $u->givePermissionTo($p);
    }

    return $u;
}

it('el super-admin gestiona roles pero NO el catálogo de permisos', function () {
    Permission::findOrCreate('administrar plataforma', 'api');
    Permission::findOrCreate('crear roles', 'api');

    Passport::actingAs(adminDeParroquia(['crear roles', 'ver roles']));
    $this->postJson('/api/roles', ['name' => 'secretaria'])->assertCreated();
    $this->postJson('/api/permissions', ['name' => 'inventar permiso'])->assertForbidden();

    Passport::actingAs(proveedor());
    $this->postJson('/api/permissions', ['name' => 'inventar permiso'])->assertCreated();
});

it('un admin de parroquia no puede otorgar el rol proveedor', function () {
    Role::findOrCreate('proveedor', 'api');

    Passport::actingAs(adminDeParroquia(['crear usuarios']));

    $this->postJson('/api/users', [
        'name' => 'X', 'email' => 'x@x.com',
        'roles' => ['proveedor'],
    ])->assertStatus(422);
});

it('el proveedor crea una parroquia con su admin y catálogo', function () {
    Permission::findOrCreate('administrar plataforma', 'api');
    Role::findOrCreate('super-admin', 'api');

    Passport::actingAs(proveedor());

    $res = $this->postJson('/api/proveedor/parroquias', [
        'nombre' => 'Parroquia Nueva',
        'admin_nombre' => 'Párroco',
        'admin_email' => 'parroco@nueva.com',
    ])->assertCreated();

    $nuevaId = $res->json('parroquia.id');
    expect($res->json('admin.temp_password'))->not->toBeEmpty();

    $sacramentos = Tenant::runFor($nuevaId, fn () => Sacramento::count());
    expect($sacramentos)->toBe(3);

    $admin = User::where('email', 'parroco@nueva.com')->first();
    expect($admin->parroquia_id)->toBe($nuevaId);
    expect($admin->hasRole('super-admin'))->toBeTrue();
});

it('una parroquia desactivada bloquea a sus usuarios pero no al proveedor', function () {
    Permission::findOrCreate('ver dashboard', 'api');
    $this->parroquia->update(['activa' => false]);

    $user = catequistaCon(['ver dashboard']);
    Passport::actingAs($user);
    $this->getJson('/api/get-user')->assertStatus(403);

    Passport::actingAs(proveedor());
    $this->getJson('/api/get-user')->assertOk();
});

it('el super-admin NO puede otorgar a un rol un permiso que él no tiene', function () {
    Permission::findOrCreate('administrar plataforma', 'api');

    Passport::actingAs(adminDeParroquia(['crear roles', 'editar roles', 'ver roles']));

    // Escalada clásica: meter 'administrar plataforma' en un rol para luego auto-asignárselo.
    $this->postJson('/api/roles', [
        'name' => 'rol-escalado',
        'permissions' => ['administrar plataforma'],
    ])->assertStatus(422);

    // El proveedor sí puede (los tiene todos).
    Passport::actingAs(proveedor());
    $this->postJson('/api/roles', [
        'name' => 'rol-plataforma',
        'permissions' => ['administrar plataforma'],
    ])->assertCreated();
});

it('el super-admin NO puede editar ni borrar roles del sistema', function () {
    $coordinador = Role::findOrCreate('coordinador', 'api');

    Passport::actingAs(adminDeParroquia(['editar roles', 'eliminar roles', 'ver roles']));

    $this->putJson("/api/roles/{$coordinador->id}", ['name' => 'coordinador-hackeado'])->assertForbidden();
    $this->deleteJson("/api/roles/{$coordinador->id}")->assertForbidden();

    expect(Role::find($coordinador->id)->name)->toBe('coordinador');
});

it('guarda etiquetas de rol en la configuración', function () {
    Permission::findOrCreate('administrar parroquia', 'api');
    Passport::actingAs(catequistaCon(['administrar parroquia']));

    $this->putJson('/api/parroquia/configuracion', [
        'programa_inicio' => null, 'programa_fin' => null,
        'dias_ventana_justificacion' => 21,
        'tipos_reunion' => ['Confirmandos'],
        'umbrales_alerta' => TenantConfig::DEFAULTS['umbrales_alerta'],
        'procedencias' => ['sede'],
        'branding' => ['nombre_publico' => null, 'logo_url' => null, 'color_primario' => '#000000'],
        'roles_labels' => ['coordinador' => 'Coordinador de Catequesis', 'catequista' => ''],
    ])->assertOk();

    expect(Tenant::config()['roles_labels'])->toBe(['coordinador' => 'Coordinador de Catequesis']);
});
