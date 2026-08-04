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

    public function run(): void
    {
        $this->call(RolePermissionUserSeeder::class);
        $this->command->info('Creando datos fijos...');

        // 2. Crear Grupos
        $grupo1 = Grupo::create(['nombre' => 'Grupo San Pablo', 'color' => '#ff5252','periodo' => '2025-2026', 'procedencia' => 'sede']);
        $grupo2 = Grupo::create(['nombre' => 'Grupo San Pedro', 'color' => '#fff652ff', 'periodo' => '2025-2026', 'procedencia' => 'sede']);
        $grupo3 = Grupo::create(['nombre' => 'Grupo María Auxiliadora', 'color' => '#7df85bff', 'periodo' => '2025-2026', 'procedencia' => 'sede']);
        $listaGruposIds = [$grupo1->id, $grupo2->id, $grupo3->id];

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
        $reqs = [];
        $nombresRequisitos = [
            // Generales
            'ActaNac' => 'Acta de nacimiento del confirmando',
            'DniConf' => 'Copia de DNI del confirmando',
            'DniApod' => 'Copia de DNI de los apoderados',
            
            // Documentos Previos
            'PartBaut' => 'Partida de Bautismo',

            // Estipendios (Pagos)
            'PagoBautismo' => 'Estipendio por Bautismo',
            'PagoComunion' => 'Estipendio por Primera Comunión',
            'PagoConfirmacion' => 'Estipendio por Confirmación',

            // Padrinos Bautismo
            'DocPadrinoBaut' => 'Constancia de Confirmación o Matrimonio del Padrino',
            'DocMadrinaBaut' => 'Constancia de Confirmación o Matrimonio de la Madrina',
            'DniPadrinoBaut' => 'Copia de DNI del Padrino',
            'DniMadrinaBaut' => 'Copia de DNI de la Madrina',

            // Padrinos Confirmación
            'DocPadrinoConf' => 'Constancia de Confirmación o Matrimonio del Padrino/Madrina',
            'DniPadrinoConf' => 'Copia de DNI del Padrino/Madrina',
        ];

        foreach ($nombresRequisitos as $key => $nombre) {
            $reqs[$key] = Requisito::create(['nombre' => $nombre])->id;
        }

        // 6. Asignar Catequistas
        $catequista1 = User::where('email', 'catequista1@test.com')->first();
        if ($catequista1) $catequista1->update(['grupo_id' => $grupo1->id]);

        $catequista2 = User::where('email', 'catequista2@test.com')->first();
        if ($catequista2) $catequista2->update(['grupo_id' => $grupo2->id]);

        $catequista3 = User::where('email', 'catequista3@test.com')->first();
        if ($catequista3) $catequista3->update(['grupo_id' => $grupo3->id]);

        // 7. Relacionar Sacramentos con Requisitos por Defecto
        // Bautismo
        $bautismo->requisitos()->attach([
            $reqs['ActaNac'], $reqs['DniConf'], $reqs['DniApod'], 
            $reqs['PagoBautismo'], $reqs['PagoComunion'], $reqs['PagoConfirmacion'],
            $reqs['DocPadrinoBaut'], $reqs['DniPadrinoBaut'], 
            $reqs['DocMadrinaBaut'], $reqs['DniMadrinaBaut']
        ]);

        // PRIMERA COMUNIÓN: Pide documentos de Comunión + LOS 2 ESTIPENDIOS RESTANTES
        $comunion->requisitos()->attach([
            $reqs['PartBaut'], $reqs['DniConf'], 
            $reqs['PagoComunion'], $reqs['PagoConfirmacion']
        ]);

        // CONFIRMACIÓN: Pide documentos de Confirmación + SOLO SU ESTIPENDIO
        $confirmacion->requisitos()->attach([
            $reqs['PartBaut'], $reqs['DniConf'], 
            $reqs['PagoConfirmacion'], 
            $reqs['DocPadrinoConf'], $reqs['DniPadrinoConf']
        ]);


        $this->command->info('Generando Confirmandos...');

        $apoderados = Apoderado::factory(100)->create();

Confirmando::factory(50)->create([
            'grupo_id' => fn() => Arr::random($listaGruposIds)
        ])->each(function ($confirmando) use ($apoderados, $tiposApoderado, $bautismo, $comunion, $confirmacion, $reqs) {

            // A. Asignar Apoderados
            $apoderadosAleatorios = $apoderados->random(rand(1, 2));
            foreach ($apoderadosAleatorios as $apoderado) {
                $confirmando->apoderados()->attach($apoderado->id, ['tipo_apoderado_id' => Arr::random($tiposApoderado)]);
            }

            // B. LÓGICA DE OBJETIVO Y ESTIPENDIOS ACUMULATIVOS
            $sacramento_faltante = Arr::random(['bautismo', 'comunion', 'confirmacion']);
            $requisitosParaAsignar = [];

            if ($sacramento_faltante === 'bautismo') {
                // --- CASO 1: LE FALTA BAUTISMO (Y TODO LO DEMÁS) ---
                // Sacramento actual: Bautismo
                $confirmando->sacramentos()->attach($bautismo->id, ['estado' => 'pendiente']);
                
                // Documentos
                $requisitosParaAsignar[] = $reqs['ActaNac'];
                $requisitosParaAsignar[] = $reqs['DniConf'];
                $requisitosParaAsignar[] = $reqs['DniApod'];
                // Padrinos Bautismo
                $requisitosParaAsignar[] = $reqs['DocPadrinoBaut'];
                $requisitosParaAsignar[] = $reqs['DniPadrinoBaut'];
                $requisitosParaAsignar[] = $reqs['DocMadrinaBaut'];
                $requisitosParaAsignar[] = $reqs['DniMadrinaBaut'];
                
                // --- CORRECCIÓN: PAGO DE LOS 3 SACRAMENTOS ---
                $requisitosParaAsignar[] = $reqs['PagoBautismo'];
                $requisitosParaAsignar[] = $reqs['PagoComunion'];
                $requisitosParaAsignar[] = $reqs['PagoConfirmacion'];

            } elseif ($sacramento_faltante === 'comunion') {
                // --- CASO 2: LE FALTA COMUNIÓN (YA TIENE BAUTISMO) ---
                $confirmando->sacramentos()->attach($bautismo->id, ['estado' => 'recibido']);
                $confirmando->sacramentos()->attach($comunion->id, ['estado' => 'pendiente']);

                // Documentos
                $requisitosParaAsignar[] = $reqs['DniConf'];
                $requisitosParaAsignar[] = $reqs['PartBaut']; // Prueba del anterior
                
                // --- CORRECCIÓN: PAGO DE LOS 2 RESTANTES ---
                $requisitosParaAsignar[] = $reqs['PagoComunion'];
                $requisitosParaAsignar[] = $reqs['PagoConfirmacion'];

            } elseif ($sacramento_faltante === 'confirmacion') {
                // --- CASO 3: SOLO LE FALTA CONFIRMACIÓN ---
                $confirmando->sacramentos()->attach($bautismo->id, ['estado' => 'recibido']);
                $confirmando->sacramentos()->attach($comunion->id, ['estado' => 'recibido']);
                $confirmando->sacramentos()->attach($confirmacion->id, ['estado' => 'pendiente']);

                // Documentos
                $requisitosParaAsignar[] = $reqs['DniConf'];
                $requisitosParaAsignar[] = $reqs['PartBaut'];
                // Padrinos Confirmación
                $requisitosParaAsignar[] = $reqs['DocPadrinoConf'];
                $requisitosParaAsignar[] = $reqs['DniPadrinoConf'];

                // --- CORRECCIÓN: PAGO SOLO DEL ÚLTIMO ---
                $requisitosParaAsignar[] = $reqs['PagoConfirmacion'];
            }

            // Guardamos los requisitos específicos
            $this->asignarRequisitos($confirmando, $requisitosParaAsignar);
        });

        $this->command->info('✅ Base de datos poblada correctamente.');
    }

    private function asignarRequisitos($confirmando, $listaIds) {
        $listaIds = array_unique($listaIds);
        foreach ($listaIds as $reqId) {
            $estado = Arr::random(['pendiente', 'entregado']);
            $confirmando->requisitos()->attach($reqId, [
                'estado' => $estado,
                'fecha_entrega' => $estado == 'entregado' ? now()->subDays(rand(1, 30)) : null,
            ]);
        }
    }
}