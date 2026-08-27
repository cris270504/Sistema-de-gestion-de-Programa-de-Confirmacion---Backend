<?php

use App\Models\Asistencia;
use App\Models\Confirmando;
use App\Models\Grupo;
use App\Models\Parroquia;
use App\Models\Reunion;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->beforeEach(function () {
        // Contexto de parroquia por defecto para los tests. Los que hacen peticiones
        // HTTP lo sobrescriben vía el middleware ResolveTenant según el usuario que
        // actúa; los tests de aislamiento usan Tenant::runFor() para las otras.
        if (! Schema::hasTable('parroquias')) {
            return; // tests sin RefreshDatabase (ExampleTest)
        }

        $parroquia = Parroquia::query()->first() ?? Parroquia::factory()->create();

        Tenant::set($parroquia->id);
        $this->parroquia = $parroquia;
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Crea un grupo mínimo válido para tests.
 */
function grupoCon(string $nombre): Grupo
{
    return Grupo::create([
        'nombre' => $nombre,
        'periodo' => '2026',
        'color' => '#2563eb',
        'procedencia' => 'sede',
    ]);
}

/**
 * Registra una falta injustificada para un confirmando (crea la reunión).
 */
function faltaInjustificada(Confirmando $c, ?Reunion $reunion = null): Asistencia
{
    $reunion ??= Reunion::create([
        'nombre_tema' => 'Reunión',
        'fecha' => now()->subDays(5),
        'tipo' => 'Confirmandos',
    ]);

    return $c->asistencias()->create([
        'reunion_id' => $reunion->id,
        'estado' => 'falta injustificada',
    ]);
}

/**
 * Catequista con un permiso y (opcionalmente) grupos asignados.
 */
function catequistaCon(array $permisos = [], array $grupoIds = []): User
{
    Role::findOrCreate('catequista', 'api');
    $user = User::factory()->create();
    $user->assignRole('catequista');
    foreach ($permisos as $p) {
        Permission::findOrCreate($p, 'api');
        $user->givePermissionTo($p);
    }
    if ($grupoIds) {
        $user->grupos()->attach($grupoIds);
    }

    return $user;
}
