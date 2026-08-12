<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apoderado;
use App\Models\Confirmando;
use App\Models\Grupo;
use Illuminate\Http\Request;

class GrupoController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // 1. Preparamos la consulta base
        // NOTA: se limitan las columnas de 'asistencias' (en vez de traer el modelo
        // completo con 'nota', timestamps, etc.) para evitar fugas de memoria cuando
        // un grupo acumula muchas reuniones/confirmandos con historial largo.
        $query = Grupo::with([
            'catequistas',
            'confirmandos' => function ($q) {
                $q->where('estado', '!=', 'retirado')
                    ->with(['asistencias:id,asistente_id,asistente_type,reunion_id,estado']);
            },
        ]);

        // 2. Filtro de Seguridad: Si NO es coordinador y NO tiene permiso global
        if (! $user->hasRole('coordinador') && ! $user->can('ver todos los grupos')) {
            // Buscamos solo los grupos donde él esté registrado en la tabla pivote
            $query->whereHas('catequistas', function ($q) use ($user) {
                // Especificamos la columna de la tabla pivote
                $q->where('catequista_grupo.user_id', $user->id);
            });
        }

        return response()->json($query->get());
    }

    public function show($id)
    {
        $grupo = Grupo::with([
            'catequistas',
            'confirmandos.apoderados',
            'confirmandos.sacramentos',
            'confirmandos.requisitos',
            'confirmandos.asistencias',
        ])->find($id);

        if (! $grupo) {
            return response()->json(['message' => 'Grupo no encontrado'], 404);
        }

        return response()->json($grupo);
    }

    // app/Http/Controllers/GruposController.php

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255', 'unique:grupos,nombre'],
            'periodo' => ['required', 'string', 'max:255'],
            'color' => ['required', 'string', 'max:7'],
            'procedencia' => ['required', 'string', 'max:7'],
        ]);

        $grupo = Grupo::create($data);
        $grupo->load(['catequistas', 'confirmandos']);

        return response()->json([
            'message' => 'Grupo creado con éxito',
            'grupo' => [
                'nombre' => $grupo->nombre,
                'periodo' => $grupo->periodo,
                'color' => $grupo->color,
                'procedencia' => $grupo->procedencia,
            ],
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $grupo = Grupo::findOrFail($id);

        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255', 'unique:grupos,nombre,'.$grupo->id],
            'periodo' => ['sometimes', 'string', 'max:255'],
            'color' => ['sometimes', 'required', 'string', 'max:7'],
            'procedencia' => ['sometimes', 'string', 'max:7'],
        ]);

        $grupo->update($data);

        $grupo->load(['catequistas', 'confirmandos']);

        return response()->json([
            'message' => 'Grupo actualizado con éxito',
            'grupo' => [
                'nombre' => $grupo->nombre,
                'periodo' => $grupo->periodo,
                'color' => $grupo->color,
                'procedencia' => $grupo->procedencia,
            ],
        ], 201);
    }

    public function destroy($id)
    {
        $grupo = Grupo::findOrFail($id);

        if ($grupo->confirmandos()->count() > 0) {
            return response()->json(['message' => 'No se puede eliminar un grupo con confirmandos asignados'], 409);
        }

        $grupo->delete();

        return response()->json(null, 204);
    }

    public function syncCatequists(Request $request, Grupo $grupo)
    {
        $data = $request->validate([
            'users' => ['nullable', 'array'],
            'users.*' => ['integer', 'exists:users,id'],
        ]);

        $newIds = $data['users'] ?? [];
        $grupo->catequistas()->sync($newIds);

        return response()->json([
            'message' => 'Catequistas actualizados',
            'grupo' => $grupo->load('catequistas'),
        ]);
    }

    public function getApoderados($id)
    {
        $grupo = Grupo::findOrFail($id);

        $apoderados = Apoderado::whereHas('confirmandos', function ($query) use ($grupo) {
            $query->where('grupo_id', $grupo->id);
        })
            ->with('confirmandos:id,nombres,apellidos')
            ->get();

        return response()->json($apoderados);
    }

    public function syncConfirmandos(Request $request, Grupo $grupo)
    {
        $data = $request->validate([
            'confirmandos' => ['nullable', 'array'],
            'confirmandos.*' => ['integer', 'exists:confirmandos,id'],
        ]);

        $newIds = $data['confirmandos'] ?? [];

        $grupo->confirmandos()->whereNotIn('id', $newIds)->update(['grupo_id' => null]);

        Confirmando::whereIn('id', $newIds)->update(['grupo_id' => $grupo->id]);

        return response()->json([
            'message' => 'Confirmandos actualizados',
            'grupo' => $grupo->load('confirmandos'),
        ]);
    }
}
