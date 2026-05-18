<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Asistencia;
use App\Models\Justificacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JustificacionController extends Controller
{

    /**
     * Listar todos los confirmandos con faltas injustificadas o con acuerdos pendientes.
     */
    public function index()
    {
        // Buscamos las asistencias que pertenezcan a Confirmandos
        $faltas = Asistencia::where('asistente_type', 'App\\Models\\Confirmando')
            ->where(function($query) {
                // Caso 1: La falta es injustificada directamente en la matriz (No se ha hablado con el padre)
                $query->where('estado', 'falta injustificada')
                
                // Caso 2: O ya existe un acuerdo registrado, pero su estado aún no es 'justificado'
                ->orWhere(function($subQuery) {
                    $subQuery->whereHas('justificacion', function($q) {
                        $q->whereIn('estado', ['injustificado', 'pendiente']);
                    });
                });
            })
            ->with([
                'reunion:id,nombre_tema,fecha',
                'justificacion',
                'asistente' => function($query) {
                    $query->select('id', 'nombres', 'apellidos', 'grupo_id')
                          ->with([
                              'grupo:id,nombre', 
                              'apoderados:id,nombres,apellidos,celular'
                          ]);
                }
            ])
            ->get();

        // Estructuramos una respuesta limpia y plana para tu Frontend de Vue 3
        $resultado = $faltas->map(function($asistencia) {
            $joven = $asistencia->asistente;
            $apoderado = $joven && $joven->apoderados->count() > 0 ? $joven->apoderados->first() : null;
            $justificacion = $asistencia->justificacion;

            return [
                'asistencia_id'        => $asistencia->id,
                'fecha_falta'          => $asistencia->reunion?->fecha,
                'tema_reunion'         => $asistencia->reunion?->nombre_tema,
                'confirmando_id'       => $joven?->id,
                'confirmando'          => $joven ? "{$joven->apellidos}, {$joven->nombres}" : 'Desconocido',
                'grupo'                => $joven?->grupo?->nombre ?? 'Sin Grupo',
                'apoderado_nombre'     => $apoderado ? "{$apoderado->apellidos}, {$apoderado->nombres}" : 'No registrado',
                'apoderado_celular'    => $apoderado?->celular ?? 'Sin celular',
                'justificacion_id'     => $justificacion?->id,
                'motivo'               => $justificacion?->motivo ?? '',
                'descripcion'          => $justificacion?->descripcion ?? '',
                'estado_justificacion' => $justificacion?->estado ?? 'injustificado' 
            ];
        });

        // Aseguramos un retorno ordenado por fecha de falta
        return response()->json($resultado->sortByDesc('fecha_falta')->values());
    }

    /**
     * Registrar el acuerdo inicial (Cambia estado a 'pendiente')
     */
    public function registrarAcuerdo(Request $request)
    {
        $request->validate([
            'asistencia_id' => 'required|exists:asistencias,id',
            'motivo'        => 'required|string|max:255',
            'descripcion'   => 'required|string', 
        ]);

        DB::beginTransaction();
        try {
            // updateOrCreate evita que se dupliquen filas si el catequista edita el acuerdo antes de que el joven cumpla
            Justificacion::updateOrCreate(
                ['asistencia_id' => $request->asistencia_id],
                [
                    'motivo'      => $request->motivo,
                    'descripcion' => $request->descripcion,
                    'estado'      => 'pendiente' 
                ]
            );

            DB::commit();
            return response()->json([
                'status'  => true,
                'message' => 'Acuerdo registrado. Estado cambiado a Pendiente.'
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Completar la acción manualmente (Cambia estado a 'justificado')
     */
    public function completarJustificacion(Request $request)
    {
        $request->validate([
            'asistencia_id' => 'required|exists:asistencias,id',
        ]);

        DB::beginTransaction();
        try {
            // 1. Pasamos la justificación a estado 'justificado'
            $justificacion = Justificacion::where('asistencia_id', $request->asistencia_id)->firstOrFail();
            $justificacion->update(['estado' => 'justificado']);

            // 2. Impactamos la tabla asistencias original para que la celda de la Matriz cambie a Azul (Falta Justificada)
            $asistencia = Asistencia::findOrFail($request->asistencia_id);
            $asistencia->update([
                'estado' => 'falta justificada',
                'nota'   => 'Justificado: ' . $justificacion->motivo
            ]);

            DB::commit();
            return response()->json([
                'status'  => true,
                'message' => 'Falta justificada formalmente. Matriz actualizada.'
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
}