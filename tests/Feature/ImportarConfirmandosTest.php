<?php

use App\Models\Confirmando;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function gestorImport(): User
{
    Permission::findOrCreate('crear confirmandos', 'api');
    Permission::findOrCreate('ver todas las asistencias', 'api');
    $u = User::factory()->create();
    $u->givePermissionTo(['crear confirmandos', 'ver todas las asistencias']);

    return $u;
}

it('importa el CSV en lote, saltea filas sin nombre y fija la parroquia', function () {
    Passport::actingAs(gestorImport());

    $csv = "nombres,celular\n"
        ."Perez Ramos Juan Carlos,987654321\n"
        .",\n"                       // fila sin nombre -> se saltea
        ."Gomez Diaz Ana,123\n";     // celular inválido -> se guarda sin celular

    $archivo = UploadedFile::fake()->createWithContent('padron.csv', $csv);

    $res = $this->postJson('/api/confirmandos/importar', ['archivo' => $archivo]);

    // Hubo una fila omitida -> 422 con el detalle, pero las válidas se guardaron.
    $res->assertStatus(422);

    expect(Confirmando::count())->toBe(2);

    $juan = Confirmando::where('nombres', 'Juan Carlos')->first();
    expect($juan)->not->toBeNull();
    expect($juan->apellidos)->toBe('Perez Ramos');
    expect($juan->parroquia_id)->toBe($this->parroquia->id);

    $ana = Confirmando::where('apellidos', 'Gomez Diaz')->first();
    expect($ana->celular)->toBeNull();
});

it('rechaza un archivo que no es planilla', function () {
    Passport::actingAs(gestorImport());

    $archivo = UploadedFile::fake()->createWithContent('malicioso.php', '<?php echo 1;');

    $this->postJson('/api/confirmandos/importar', ['archivo' => $archivo])
        ->assertStatus(422)
        ->assertJsonValidationErrors('archivo');
});

it('todas las inserciones ocurren en una transacción', function () {
    Passport::actingAs(gestorImport());

    $csv = "nombres,celular\nUno Dos Tres,\nCuatro Cinco Seis,\n";
    $archivo = UploadedFile::fake()->createWithContent('ok.csv', $csv);

    $this->postJson('/api/confirmandos/importar', ['archivo' => $archivo])->assertOk();

    expect(Tenant::runFor($this->parroquia->id, fn () => Confirmando::count()))->toBe(2);
});
