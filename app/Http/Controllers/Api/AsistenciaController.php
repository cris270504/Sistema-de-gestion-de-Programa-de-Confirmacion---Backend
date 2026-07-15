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
        $user = $request->user(); // <-- NUEVO: Obtenemos al usuario logueado

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
            $queryConfirmandos = Confirmando::with([
                'grupo',
                'asistencias' => function ($q) use ($reunionIds) {
                    $q->whereIn('reunion_id', $reunionIds);
                }])
                ->where('estado', '!=', 'retirado');

            // <-- NUEVO FILTRO DE SEGURIDAD BACKEND -->
            if (!$user->hasRole('coordinador') && !$user->can('ver todas las asistencias')) {
                // Solo traemos a los jóvenes cuyo grupo_id coincida con los grupos del catequista
                $queryConfirmandos->whereIn('grupo_id', $user->grupos->pluck('id'));
            }

            $personas = $queryConfirmandos->orderBy('apellidos')->get();
            
        } elseif ($tipo === 'Catequistas') {
            // ... tu código de catequistas sigue igual ...
}
