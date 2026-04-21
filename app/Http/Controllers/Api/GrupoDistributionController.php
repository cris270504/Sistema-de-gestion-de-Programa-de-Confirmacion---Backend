<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Confirmando;
use App\Models\Grupo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GrupoDistributionController extends Controller
{
    public function generarGruposEquitativos(Request $request)
    {
        $request->validate([
            'nombres_grupos' => 'required|array|min:1',
            'nombres_grupos.*' => 'required|string|distinct',
            'periodo' => 'required|string',
        ]);

        $nombres = $request->nombres_grupos;
        $cantidadGrupos = count($nombres);

        // 1. Obtener confirmandos
        $hombres = Confirmando::whereNull('grupo_id')
            ->where(function ($query) {
                $query->whereRaw('TIMESTAMPDIFF(YEAR, fecha_nacimiento, CURDATE()) BETWEEN 14 AND 17')
                    ->orWhereNull('fecha_nacimiento');
            })
            ->where('estado', 'en_preparacion')
            ->whereIn('genero', ['M', 'm'])
            ->orderByDesc('fecha_nacimiento')
            ->get();

        $mujeres = Confirmando::whereNull('grupo_id')
            ->where(function ($query) {
                $query->whereRaw('TIMESTAMPDIFF(YEAR, fecha_nacimiento, CURDATE()) BETWEEN 14 AND 17')
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
                        'periodo' => $request->periodo,
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
            foreach ($gruposCreados as $data) {
                if (! empty($data['ids_asignar'])) {
                    Confirmando::whereIn('id', $data['ids_asignar'])
                        ->update(['grupo_id' => $data['model']->id]);

                    $totalAsignados += count($data['ids_asignar']);
                }
            }

            DB::commit();

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
