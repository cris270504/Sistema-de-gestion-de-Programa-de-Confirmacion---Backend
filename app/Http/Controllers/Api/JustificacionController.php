<?php

namespace App\Http\Controllers\Api;

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
        // Traemos todas las asistencias del tipo Confirmando que sean faltas
        $faltas = Asistencia::where('asistente_type', 'App\\Models\\Confirmando')
            ->where(function ($query) {
                // Caso A: El estado de la asistencia es falta injustificada en la tabla principal
                $query->where('estado', 'falta injustificada')
                // Caso B: O simplemente tiene una justificación registrada (sea el estado que sea)
                    ->orWhereHas('justificacion');
            })
            ->whereHas('asistente', function ($query) {
                $query->where('estado', '!=', 'retirado');
                // Nota: Si en tu tabla de confirmandos la columna se llama 'activo' (1 o 0),
                // cambia la línea de arriba por: $query->where('activo', 1);
            })
            ->with([
                'reunion:id,nombre_tema,fecha',
                'justificacion',
                'asistente' => function ($query) {
                    // Aquí mantenemos la carga de las relaciones para el mapa
                    $query->select('id', 'nombres', 'apellidos', 'grupo_id')
                        ->with([
                            'grupo:id,nombre',
                            'apoderados:id,nombres,apellidos,celular',
                        ]);
                },
            ])
            ->get();

        $resultado = $faltas->map(function ($asistencia) {
            $joven = $asistencia->asistente;
            $apoderado = $joven && $joven->apoderados->count() > 0 ? $joven->apoderados->first() : null;
            $justificacion = $asistencia->justificacion;

            return [
                'asistencia_id' => $asistencia->id,
                'fecha_falta' => $asistencia->reunion?->fecha,
                'tema_reunion' => $asistencia->reunion?->nombre_tema,
                'confirmando_id' => $joven?->id,
                'confirmando' => $joven ? "{$joven->apellidos}, {$joven->nombres}" : 'Desconocido',
                'grupo' => $joven?->grupo?->nombre ?? 'Sin Grupo',
                'apoderado_nombre' => $apoderado ? "{$apoderado->apellidos}, {$apoderado->nombres}" : 'No registrado',
                'apoderado_celular' => $apoderado?->celular ?? 'Sin celular',
                'justificacion_id' => $justificacion?->id,
                'motivo' => $justificacion?->motivo ?? '',
                'descripcion' => $justificacion?->descripcion ?? '',
                'estado_justificacion' => $justificacion?->estado ?? 'injustificado',
            ];
        });

        // Lo ordenamos de manera descendente por la fecha de la reunión
        return response()->json($resultado->sortByDesc('fecha_falta')->values());
    }

    /**
     * Registrar el acuerdo inicial (Cambia estado a 'pendiente')
     */
    public function registrarAcuerdo(Request $request)
    {
        $request->validate([
            'asistencia_id' => 'required|exists:asistencia,id',
            'motivo' => 'required|string|max:255',
            'descripcion' => 'required|string',
        ]);

        DB::beginTransaction();
        try {
            Justificacion::updateOrCreate(
                ['asistencia_id' => $request->asistencia_id],
                [
                    'motivo' => $request->motivo,
                    'descripcion' => $request->descripcion,
                    'estado' => 'pendiente',
                ]
            );

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Acuerdo registrado. Estado cambiado a Pendiente.',
            ]);

        } catch (\Exception $e) {
            DB::rollback();

            return response()->json(['status' => false, 'message' => 'Error: '.$e->getMessage()], 500);
        }
    }

    /**
     * Completar la acción manualmente (Cambia estado a 'justificado')
     */
    public function completarJustificacion(Request $request)
    {
        $request->validate([
            'asistencia_id' => 'required|exists:asistencia,id',
        ]);

        DB::beginTransaction();
        try {
            $justificacion = Justificacion::where('asistencia_id', $request->asistencia_id)->firstOrFail();
            $justificacion->update(['estado' => 'justificado']);

            $asistencia = Asistencia::findOrFail($request->asistencia_id);
            $asistencia->update([
                'estado' => 'falta justificada',
                'nota' => 'Justificado: '.$justificacion->motivo,
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Falta justificada formalmente. Matriz actualizada.',
            ]);

        } catch (\Exception $e) {
            DB::rollback();

            return response()->json(['status' => false, 'message' => 'Error: '.$e->getMessage()], 500);
        }
    }
}
