<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apoderado;
use App\Models\Asistencia;
use App\Models\Confirmando;
use App\Models\Reunion;
use App\Models\User;
use Illuminate\Http\Request;

class AsistenciaController extends Controller
{
    public function index($reunionId)
    {
        return Asistencia::where('reunion_id', $reunionId)->get();
    }

    public function store(Request $request, $reunionId)
    {
        $data = $request->validate([
            'asistencias' => ['required', 'array'],
            'asistencias.*.asistente_id' => ['required', 'integer'],
            'asistencias.*.asistente_type' => ['required', 'string'],
            'asistencias.*.estado' => ['required', 'in:asistio,tardanza,falta justificada,falta injustificada'],
            'asistencias.*.nota' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($data['asistencias'] as $registro) {
            Asistencia::updateOrCreate(
                [
                    // Condiciones de búsqueda (WHERE)
                    'reunion_id' => $reunionId,
                    'asistente_id' => $registro['asistente_id'],
                    'asistente_type' => $registro['asistente_type'],
                ],
                [
                    // Valores a actualizar o crear (SET)
                    'estado' => $registro['estado'],

                    // 2. AGREGADO: Mapeo de 'observacion' (frontend) a 'nota' (backend)
                    'nota' => $registro['nota'] ?? null,
                ]
            );
        }

        return response()->json(['message' => 'Asistencia guardada correctamente']);
    }

    public function matrix(Request $request)
    {
        $tipo = $request->query('tipo', 'Confirmandos');
        $fecha = $request->query('fecha');

        $queryReuniones = Reunion::where('tipo', $tipo)->orderBy('fecha', 'asc');

        if ($fecha) {
            [$year, $month] = explode('-', $fecha);
            $queryReuniones->whereYear('fecha', $year)->whereMonth('fecha', $month);
        }

        $reuniones = $queryReuniones->get(['id', 'nombre_tema', 'fecha', 'tipo']);

        if ($reuniones->isEmpty()) {
            return response()->json(['reuniones' => [], 'personas' => []]);
        }

        $personas = [];
        $reunionIds = $reuniones->pluck('id');

        if ($tipo === 'Confirmandos') {
            $personas = Confirmando::with([
                'grupo',
                'asistencias' => function ($q) use ($reunionIds) {
                    $q->whereIn('reunion_id', $reunionIds);
                }])
                ->where('estado', '!=', 'retirado')
                ->orderBy('apellidos')
                ->get();
        } elseif ($tipo === 'Catequistas') {
            $personas = User::role(['catequista', 'coordinador'])
                ->with(['grupos', 'roles', 'asistencias' => function ($q) use ($reunionIds) {
                    $q->whereIn('reunion_id', $reunionIds);
                }])
                ->orderBy('name')
                ->get();

        } elseif ($tipo === 'Apoderados') {
            $personas = Apoderado::with(['confirmandos.grupo', 'asistencias' => function ($q) use ($reunionIds) {
                $q->WhereIn('reunion_id', $reunionIds);
            }])
                ->orderBy('apellidos')
                ->get();
        }

        return response()->json([
            'reuniones' => $reuniones,
            'personas' => $personas,
        ]);
    }
}
