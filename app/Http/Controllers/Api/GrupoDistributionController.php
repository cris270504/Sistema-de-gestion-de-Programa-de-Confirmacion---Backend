<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerarGruposEquitativosRequest;
use App\Models\Confirmando;
use App\Models\Grupo;
use Illuminate\Support\Facades\DB;

class GrupoDistributionController extends Controller
{
    public function generarGruposEquitativos(GenerarGruposEquitativosRequest $request)
    {
        $nombres = $request->validated()['nombres_grupos'];
        $periodo = $request->validated()['periodo'];
        $cantidadGrupos = count($nombres);

        // Rango de fecha de nacimiento equivalente a "edad completa entre 14 y 17 años",
        // calculado en PHP para no depender de funciones SQL propietarias
        // (TIMESTAMPDIFF/CURDATE son de MySQL y no existen en PostgreSQL).
        $fechaNacimientoMax = now()->subYears(14)->toDateString();
        $fechaNacimientoMin = now()->subYears(18)->addDay()->toDateString();

        // 1. Obtener confirmandos
        $hombres = Confirmando::whereNull('grupo_id')
            ->where(function ($query) use ($fechaNacimientoMin, $fechaNacimientoMax) {
                $query->whereBetween('fecha_nacimiento', [$fechaNacimientoMin, $fechaNacimientoMax])
                    ->orWhereNull('fecha_nacimiento');
            })
            ->where('estado', 'en_preparacion')
            ->whereIn('genero', ['M', 'm'])
            ->orderByDesc('fecha_nacimiento')
            ->get();

        $mujeres = Confirmando::whereNull('grupo_id')
            ->where(function ($query) use ($fechaNacimientoMin, $fechaNacimientoMax) {
                $query->whereBetween('fecha_nacimiento', [$fechaNacimientoMin, $fechaNacimientoMax])
                    ->orWhereNull('fecha_nacimiento');
            })
            ->where('estado', 'en_preparacion')
            ->whereIn('genero', ['F', 'f'])
            ->orderByDesc('fecha_nacimiento')
            ->get();

        $totalConfirmandos = $hombres->count() + $mujeres->count();

        if ($totalConfirmandos == 0) {
            return response()->json(['message' => 'No hay confirmandos disponibles para asignar. Asegúrate de que tengan género definido y no estén ya en un grupo.'], 400);
        }

        DB::beginTransaction();
        try {
            $gruposCreados = [];
            $contadorNuevos = 0; // <--- CONTADOR DE GRUPOS NUEVOS

            // 2. Procesar Grupos
            foreach ($nombres as $nombre) {
                $nombreLimpio = trim($nombre);

                $grupo = Grupo::firstOrCreate(
                    [
                        'nombre' => $nombreLimpio,
                        'periodo' => $periodo,
                    ],
                    [
                        'color' => '#'.str_pad(dechex(rand(0x000000, 0xFFFFFF)), 6, '0', STR_PAD_LEFT),
                    ]
                );

                // VERIFICAMOS SI FUE CREADO RECIENTEMENTE
                if ($grupo->wasRecentlyCreated) {
                    $contadorNuevos++;
                }

                $gruposCreados[] = [
                    'model' => $grupo,
                    'ids_asignar' => [],
                ];
            }

            // 3. Algoritmo Round Robin (Igual que antes)
            foreach ($hombres as $index => $hombre) {
                $targetIndex = $index % $cantidadGrupos;
                $gruposCreados[$targetIndex]['ids_asignar'][] = $hombre->id;
            }

            foreach ($mujeres as $index => $mujer) {
                $targetIndex = $index % $cantidadGrupos;
                $gruposCreados[$targetIndex]['ids_asignar'][] = $mujer->id;
            }

            // 4. Actualizaciones Masivas
            $totalAsignados = 0;
            $asignaciones = []; // confirmando_id => grupo_id (para que el front parche su lista sin recargar)
            foreach ($gruposCreados as $data) {
                if (! empty($data['ids_asignar'])) {
                    Confirmando::whereIn('id', $data['ids_asignar'])
                        ->update(['grupo_id' => $data['model']->id]);

                    foreach ($data['ids_asignar'] as $confirmandoId) {
                        $asignaciones[$confirmandoId] = $data['model']->id;
                    }

                    $totalAsignados += count($data['ids_asignar']);
                }
            }

            DB::commit();

            DashboardController::invalidate();

            // 5. CONSTRUCCIÓN DEL MENSAJE DINÁMICO
            $mensaje = '';

            if ($contadorNuevos === $cantidadGrupos) {
                // Caso A: Todos son nuevos
                $mensaje = "Se crearon $cantidadGrupos nuevos grupos y se asignaron $totalAsignados confirmandos.";
            } elseif ($contadorNuevos === 0) {
                // Caso B: Todos ya existían
                $mensaje = "Se asignaron $totalAsignados confirmandos a $cantidadGrupos grupos existentes.";
            } else {
                // Caso C: Mezcla (se creó uno nuevo y se usaron existentes)
                $existentes = $cantidadGrupos - $contadorNuevos;
                $mensaje = "Se crearon $contadorNuevos grupos, se usaron $existentes existentes y se asignaron $totalAsignados confirmandos.";
            }

            return response()->json([
                'message' => $mensaje,
                'total_asignados' => $totalAsignados,
                'grupos_nuevos' => $contadorNuevos,
                // Para que el frontend actualice su estado local sin re-descargar toda
                // la lista de confirmandos ni la de grupos.
                'asignaciones' => $asignaciones,
                'grupos' => Grupo::where('periodo', $periodo)->get(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al procesar grupos',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
