<?php

namespace Database\Seeders;

use App\Models\Apoderado;
use App\Models\Confirmando;
use App\Models\Grupo;
use App\Models\Requisito;
use App\Models\Sacramento;
use App\Models\TipoApoderado;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Ejecuta el Seeder de Roles y Permisos primero
        // Asegúrate que tu seeder de roles cree los usuarios de prueba
        $this->call(RolePermissionUserSeeder::class);

        $this->command->info('Creando datos fijos (Grupos, Sacramentos, Requisitos)...');

        // 2. Crear Grupos
        $grupo1 = Grupo::create(['nombre' => 'Grupo San Pablo', 'periodo' => '2025-2026']);
        $grupo2 = Grupo::create(['nombre' => 'Grupo San Pedro', 'periodo' => '2025-2026']);
        $grupo3 = Grupo::create(['nombre' => 'Grupo María Auxiliadora', 'periodo' => '2025-2026']);

        // 3. Crear Tipos de Apoderado
        $tipoPadre = TipoApoderado::create(['nombre' => 'Padre']);
        $tipoMadre = TipoApoderado::create(['nombre' => 'Madre']);
        $tipoTutor = TipoApoderado::create(['nombre' => 'Tutor Legal']);
        $tiposApoderado = [$tipoPadre->id, $tipoMadre->id, $tipoTutor->id];

        // 4. Crear Sacramentos
        $bautismo = Sacramento::create(['nombre' => 'Bautismo']);
        $comunion = Sacramento::create(['nombre' => 'Primera Comunión']);
        $confirmacion = Sacramento::create(['nombre' => 'Confirmación']);

        // 5. Crear Requisitos
        $listaRequisitos = [
            'Acta de nacimiento del confirmando', 'Copia de DNI del confirmando', 'Partida de Bautismo del confirmando',
            'Estipendio', 'Partida de Confirmación del Padrino', 'Partida de Confirmación de la Madrina',
            'Partida de Matrimonio del Padrino', 'Partida de Matrimonio de la Madrina', 'Copia de DNI del Padrino',
            'Copia de DNI de la Madrina', 'Copia de DNI de los apoderados',
        ];
        $requisitos = collect(); // Usamos una colección para guardarlos
        foreach ($listaRequisitos as $nombre) {
            $requisitos->push(Requisito::create(['nombre' => $nombre]));
        }

        // 6. Asignar Catequistas a Grupos (de los usuarios de prueba)
        // ASIGNACIÓN 1
        $catequista1 = User::where('email', 'cristopher@test.com')->first();
        if ($catequista1) {
            $catequista1->update(['grupo_id' => $grupo1->id]);
        }

        // ASIGNACIÓN 2 (CORREGIDO)
        $catequista2 = User::where('email', 'Domenick@test.com')->first();
        if ($catequista2) {
            $catequista2->update(['grupo_id' => $grupo2->id]);
        }

        // ASIGNACIÓN 3 (CORREGIDO)
        $catequista3 = User::where('email', 'Requena@test.com')->first();
        if ($catequista3) {
            $catequista3->update(['grupo_id' => $grupo3->id]);
        }

        $catequista4 = User::where('email', 'yenn@test.com')->first();
        if ($catequista4) {
            $catequista4->update(['grupo_id' => $grupo3->id]);
        }

        // 7. Relacionar Sacramentos con Requisitos (Lógica de negocio) (CORREGIDO)
        $this->command->info('Asignando requisitos a sacramentos...');

        // Requisitos para Bautismo
        $bautismo->requisitos()->attach([
            $requisitos->where('nombre', 'Acta de nacimiento del confirmando')->first()->id,
            $requisitos->where('nombre', 'Copia de DNI del confirmando')->first()->id,
            $requisitos->where('nombre', 'Copia de DNI de los apoderados')->first()->id,
            $requisitos->where('nombre', 'Partida de Confirmación del Padrino')->first()->id,
            $requisitos->where('nombre', 'Partida de Matrimonio del Padrino')->first()->id,
            $requisitos->where('nombre', 'Partida de Confirmación de la Madrina')->first()->id,
            $requisitos->where('nombre', 'Partida de Matrimonio de la Madrina')->first()->id,
            $requisitos->where('nombre', 'Copia de DNI del Padrino')->first()->id,
            $requisitos->where('nombre', 'Copia de DNI de la Madrina')->first()->id,
        ]);

        // Requisitos para Primera Comunión
        $comunion->requisitos()->attach([
            $requisitos->where('nombre', 'Partida de Bautismo del confirmando')->first()->id,
            $requisitos->where('nombre', 'Copia de DNI del confirmando')->first()->id,
        ]);

        // Requisitos para Confirmación (La lógica 'OR' se maneja en la app)
        $confirmacion->requisitos()->attach([
            $requisitos->where('nombre', 'Partida de Bautismo del confirmando')->first()->id,
            $requisitos->where('nombre', 'Copia de DNI del confirmando')->first()->id,
            $requisitos->where('nombre', 'Partida de Confirmación del Padrino')->first()->id,
            $requisitos->where('nombre', 'Partida de Confirmación de la Madrina')->first()->id,
            $requisitos->where('nombre', 'Partida de Matrimonio del Padrino')->first()->id,
            $requisitos->where('nombre', 'Partida de Matrimonio de la Madrina')->first()->id,
            $requisitos->where('nombre', 'Copia de DNI del Padrino')->first()->id,
            $requisitos->where('nombre', 'Copia de DNI de la Madrina')->first()->id,
        ]);


        $this->command->info('Creando datos falsos (Apoderados y Confirmandos)...');

        // 8. Crear Apoderados falsos (usando la Factory)
        $apoderados = Apoderado::factory(100)->create(); // Crea 100 apoderados

        // 9. Crear Confirmandos falsos y sus relaciones (usando la Factory)
        // (CORREGIDO: Se añadieron $bautismo, $comunion, $confirmacion al 'use')
        Confirmando::factory(50)->create()->each(function ($confirmando) use ($apoderados, $tiposApoderado, $requisitos, $bautismo, $comunion, $confirmacion) {

            // A. Asignar 1 o 2 apoderados aleatorios a este confirmando
            $apoderadosAleatorios = $apoderados->random(rand(1, 2));
            foreach ($apoderadosAleatorios as $apoderado) {
                $confirmando->apoderados()->attach($apoderado->id, [
                    'tipo_apoderado_id' => Arr::random($tiposApoderado), // Elige un tipo al azar
                ]);
            }

            // B. Asignar sacramentos (simulando su estado)
            // Todos están pendientes de Confirmación
            $confirmando->sacramentos()->attach($confirmacion->id, ['estado' => 'Pendiente']);

            // Algunos están pendientes de Bautismo, otros ya lo tienen
            $confirmando->sacramentos()->attach($bautismo->id, [
                'estado' => Arr::random(['Pendiente', 'Recibido']),
            ]);
            $confirmando->sacramentos()->attach($comunion->id, [
                'estado' => Arr::random(['Pendiente', 'Recibido']),
            ]);

            // C. Asignar requisitos (simulando su estado)
            // Esta lógica ahora es más compleja. Asignaremos requisitos basados en sacramentos pendientes.
            // Para este seeder, mantendremos la lógica aleatoria simple para tener datos de prueba.
            // La lógica real de asignación debe estar en tu aplicación (al crear un Confirmando).
            $requisitosAleatorios = $requisitos->random(rand(3, 6)); // Asigna entre 3 y 6 requisitos al azar
            foreach ($requisitosAleatorios as $requisito) {
                $estado = Arr::random(['Pendiente', 'Entregado']);
                $confirmando->requisitos()->attach($requisito->id, [
                    'estado' => $estado,
                    'fecha_entrega' => $estado != 'Pendiente' ? now()->subDays(rand(1, 60)) : null,
                ]);
            }
        });

        $this->command->info('✅ ¡Base de datos poblada con datos de prueba!');
    }
}