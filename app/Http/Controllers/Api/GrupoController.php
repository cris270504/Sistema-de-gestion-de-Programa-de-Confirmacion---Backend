<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Confirmando;
use App\Models\Grupo;
use Illuminate\Http\Request;

class GrupoController extends Controller
{
    public function index()
    {
        return Grupo::with(['confirmandos', 'catequistas'])->latest()->get();
    }

    public function show($id)
    {
        return Grupo::with(['confirmandos', 'catequistas'])->findOrFail($id);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255', 'unique:grupos,nombre'],
            'periodo' => ['required', 'string', 'max:255'],
        ]);

        $grupo = Grupo::create($data);
        $grupo->load(['catequistas', 'confirmandos']);

        return response()->json([
            'message' => 'Grupo creado con éxito',
            'grupo' => [
                'nombre' => $grupo->nombre,
                'periodo' => $grupo->periodo,
            ],
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $grupo = Grupo::findOrFail($id);

        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255', 'unique:grupos,nombre,'.$grupo->id],
            'periodo' => ['sometimes', 'string', 'max:255'],
        ]);

        $grupo->update($data);

        $grupo->load(['catequistas', 'confirmandos']);

        return response()->json([
            'message' => 'Grupo actualizado con éxito',
            'grupo' => [
                'nombre' => $grupo->nombre,
                'periodo' => $grupo->periodo,
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
        $grupo->catequistas()->whereNotIn('id', $newIds)->update(['grupo_id' => null]);
        \App\Models\User::whereIn('id', $newIds)->update(['grupo_id' => $grupo->id]);

        return response()->json([
            'message' => 'Catequistas actualizados',
            'grupo' => $grupo->load('catequistas'),
        ]);
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
