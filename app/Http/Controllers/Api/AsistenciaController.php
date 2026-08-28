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
        // La reunión debe existir: sin esto, un id inválido revienta con un 500 por
        // violación de FK en vez de un 404 claro.
        Reunion::findOrFail($reunionId);

        $data = $request->validate([
            'asistencias' => ['required', 'array'],
            'asistencias.*.asistente_id' => ['required', 'integer'],
            'asistencias.*.asistente_type' => ['required', 'string'],
            'asistencias.*.estado' => ['required', 'in:asistio,tardanza,falta justificada,falta injustificada'],
            'asistencias.*.nota' => ['nullable', 'string', 'max:255'],
        ]);

        // Antes: un updateOrCreate() por fila => 2 queries por persona (SELECT + UPSERT).
        // Es el momento en que el catequista espera con el celular al terminar la reunión.
        // Ahora: 1 SELECT para todas + INSERT masivo de las nuevas + UPDATE de las que
        // ya existían. En la primera toma de asistencia de una reunión (todo altas) son
        // solo 2 queries en total. No se usa Asistencia::upsert() porque la tabla no
        // tiene índice único en (reunion_id, asistente_id, asistente_type).
        $existentes = Asistencia::where('reunion_id', $reunionId)
            ->get(['id', 'asistente_id', 'asistente_type'])
            ->keyBy(fn ($a) => $a->asistente_type.'|'.$a->asistente_id);

        $nuevas = [];
        $ahora = now();

        foreach ($data['asistencias'] as $registro) {
            $clave = $registro['asistente_type'].'|'.$registro['asistente_id'];
            $existente = $existentes->get($clave);

            if ($existente) {
                Asistencia::where('id', $existente->id)->update([
                    'estado' => $registro['estado'],
                    'nota' => $registro['nota'] ?? null,
                ]);
            } else {
                $nuevas[] = [
                    'reunion_id' => $reunionId,
                    'asistente_id' => $registro['asistente_id'],
                    'asistente_type' => $registro['asistente_type'],
                    'estado' => $registro['estado'],
                    'nota' => $registro['nota'] ?? null,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }
        }

        if (! empty($nuevas)) {
            Asistencia::insert($nuevas);
        }

        // Las asistencias alimentan las alertas del dashboard: refrescamos su cache.
        DashboardController::invalidate();

        return response()->json(['message' => 'Asistencia guardada correctamente']);
    }

    public function matrix(Request $request)
    {
        $tipo = $request->query('tipo', 'Confirmandos');
        $fecha = $request->query('fecha');
        $user = $request->user();

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
            $query = Confirmando::with([
                'grupo',
                'asistencias' => function ($q) use ($reunionIds) {
                    $q->whereIn('reunion_id', $reunionIds);
                }])
                ->where('estado', '!=', 'retirado');

            if (! $user->hasRole('coordinador') && ! $user->can('ver todas las asistencias')) {
                $query->whereIn('grupo_id', $user->grupos->pluck('id'));
            }

            $personas = $query->orderBy('apellidos')->get();
        } elseif ($tipo === 'Catequistas') {
            $personas = User::role(['catequista', 'coordinador'])
                ->parroquiaActual()
                ->with(['grupos', 'roles', 'asistencias' => function ($q) use ($reunionIds) {
                    $q->whereIn('reunion_id', $reunionIds);
                }])
                ->orderBy('name')
                ->get();

        } elseif ($tipo === 'Apoderados') {
            $personas = Apoderado::with(['confirmandos.grupo', 'asistencias' => function ($q) use ($reunionIds) {
                $q->whereIn('reunion_id', $reunionIds);
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
