<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asistencia;
use App\Models\Justificacion;
use App\Models\Reunion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class JustificacionController extends Controller
{
    public function index()
    {
        // 1. Calculamos la fecha límite (hace 21 días a la medianoche)
        $hace21Dias = Carbon::now()->subDays(21)->startOfDay();

        // Opcional: Si quieres que tampoco salgan reuniones futuras
        $hoy = Carbon::now()->endOfDay();

        // 2. Hacemos la consulta filtrando por el campo 'fecha' (o como se llame en tu tabla)
        $reuniones = Reunion::where('fecha', '>=', $hace21Dias)
            ->where('fecha', '<=', $hoy) // Quita esta línea si las reuniones futuras sí deben mostrarse
            ->orderBy('fecha', 'desc')
            ->get();

        return response()->json($reuniones);
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

    public function rechazarAcuerdo($id)
    {
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
