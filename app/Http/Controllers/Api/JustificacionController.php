<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asistencia;
use App\Models\Confirmando;
use App\Models\Justificacion;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JustificacionController extends Controller
{
    /**
     * ¿El usuario ve/gestiona TODAS las justificaciones, o solo las de sus grupos?
     */
    private function esPrivilegiado(Request $request): bool
    {
        return $request->user()->hasAnyRole(['coordinador', 'super-admin']);
    }

    /**
     * Verifica que la asistencia pertenezca a un confirmando de los grupos del
     * catequista actual. Aborta con 404 si no (mismo comportamiento que RLS en prod).
     */
    private function autorizarAsistencia(Request $request, int $asistenciaId): void
    {
        if ($this->esPrivilegiado($request)) {
            return;
        }

        $gruposIds = $request->user()->grupos->pluck('id');

        $permitida = Asistencia::where('id', $asistenciaId)
            ->where('asistente_type', Confirmando::class)
            ->whereHasMorph('asistente', [Confirmando::class], function ($q) use ($gruposIds) {
                $q->whereIn('grupo_id', $gruposIds);
            })
            ->exists();

        abort_unless($permitida, 404, 'Falta no encontrada.');
    }

    /**
     * Listar todos los confirmandos con faltas injustificadas o con acuerdos pendientes.
     */
    public function index(Request $request)
    {
        // 1. Calculamos la fecha límite dinámica (hace 21 días atrás a las 00:00:00)
        $hace21Dias = Carbon::now()->subDays(21)->startOfDay();
        $hoy = Carbon::now()->endOfDay(); // Para evitar traer reuniones de fechas futuras erróneas

        // Traemos todas las asistencias del tipo Confirmando que sean faltas
        $faltas = Asistencia::where('asistente_type', 'App\\Models\\Confirmando')
            // El catequista solo ve las faltas de los confirmandos de sus grupos.
            ->when(! $this->esPrivilegiado($request), function ($query) use ($request) {
                $gruposIds = $request->user()->grupos->pluck('id');
                $query->whereHasMorph('asistente', [Confirmando::class], function ($q) use ($gruposIds) {
                    $q->whereIn('grupo_id', $gruposIds);
                });
            })
            ->where(function ($query) {
                // Caso A: Es una falta injustificada pura
                $query->where('estado', 'falta injustificada')
                    // Caso B: O tiene un acuerdo/justificación, pero que NO haya sido marcado como "no cumplido"
                    ->orWhereHas('justificacion', function ($q) {
                        $q->where('estado', '!=', 'no_cumplido');
                    });
            })
            // 2. Filtro crucial: Excluir por completo a los chicos que ya se retiraron
            ->whereHas('asistente', function ($query) {
                $query->where('estado', '!=', 'retirado');
            })
            // ➔ 3. FILTRO CORREGIDO: El límite de tiempo solo aplica a las injustificadas puras
            ->where(function ($query) use ($hace21Dias, $hoy) {
                // Caso A: Si la falta tiene un trámite (pendiente o justificado), pasa libre sin importar la fecha
                $query->whereHas('justificacion', function ($q) {
                    $q->whereIn('estado', ['pendiente', 'justificado']);
                })
                // Caso B: Si es injustificada pura, obligatoriamente debe estar dentro de los 21 días
                    ->orWhere(function ($q) use ($hace21Dias, $hoy) {
                        $q->where('estado', 'falta injustificada')
                            ->whereHas('reunion', function ($subQ) use ($hace21Dias, $hoy) {
                                $subQ->where('fecha', '>=', $hace21Dias)
                                    ->where('fecha', '<=', $hoy);
                            });
                    });
            })
            // 4. Carga optimizada de relaciones para armar el JSON
            ->with([
                'reunion:id,nombre_tema,fecha',
                'justificacion',
                'asistente' => function ($query) {
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
                'fecha_acuerdo' => $justificacion?->fecha_acuerdo ?? '',
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
            'fecha_acuerdo' => 'required|date',
        ]);

        $this->autorizarAsistencia($request, (int) $request->asistencia_id);

        DB::beginTransaction();
        try {
            Justificacion::updateOrCreate(
                ['asistencia_id' => $request->asistencia_id],
                [
                    'motivo' => $request->motivo,
                    'descripcion' => $request->descripcion,
                    'fecha_acuerdo' => $request->fecha_acuerdo,
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

        $this->autorizarAsistencia($request, (int) $request->asistencia_id);

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

    public function rechazarAcuerdo(Request $request, $id)
    {
        $this->autorizarAsistencia($request, (int) $id);

        DB::beginTransaction();
        try {
            $asistencia = Asistencia::findOrFail($id);
            $justificacion = $asistencia->justificacion;

            if (! $justificacion) {
                DB::rollback();

                return response()->json(['status' => false, 'message' => 'No se encontró un acuerdo registrado.'], 404);
            }

            $descripcionActual = $justificacion->descripcion ?? '';
            $nuevaDescripcion = trim($descripcionActual."\n\n[NOTA: NO CUMPLIÓ CON LA ACCIÓN PACTADA]");

            // 1. Cambiamos el estado a no_cumplido y estampamos la nota de auditoría
            $justificacion->update([
                'estado' => 'no_cumplido',
                'descripcion' => $nuevaDescripcion,
            ]);

            // 2. Saneamos el estado de la asistencia principal en la matriz
            $asistencia->update([
                'estado' => 'falta injustificada',
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Falta marcada como no cumplida con éxito y archivada.',
            ]);

        } catch (\Exception $e) {
            DB::rollback();

            return response()->json([
                'status' => false,
                'message' => 'Error al procesar la solicitud: '.$e->getMessage(),
            ], 500);
        }
    }
}
