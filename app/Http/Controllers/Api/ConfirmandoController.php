<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Confirmando;
use Illuminate\Http\Request;

class ConfirmandoController extends Controller
{
    public function index()
    {
        return Confirmando::with('grupo')->latest()->get();
    }

    public function show($id)
    {
        return Confirmando::with('grupo')->findOrFail($id);
    }

    public function store(Request $request)
    {
        $maxDate = now()->subYears(14)->format('Y-m-d');

        $validate = $request->validate([
            'nombres' => ['required', 'string'],
            'apellidos' => ['required', 'string'],
            'celular' => ['nullable', 'string', 'max:9'],
            'fecha_nacimiento' => ['required', 'date', 'before_or_equal:' . $maxDate],
            'grupo_id' => ['nullable', 'exists:grupos,id'],
            ['fecha_nacimiento.before_or_equal' => 'El confirmando debe tener al menos 14 años.']
        ]);

        $confirmando = Confirmando::create($validate);
        $confirmando->load('grupo');

        return response()->json([
            'message' => 'Confirmando creado con éxito',
            'confirmando' => [
                'nombres' => $confirmando->nombres,
                'apellidos' => $confirmando->apellidos,
            ],
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $maxDate = now()->subYears(14)->format('Y-m-d');
        $confirmando = Confirmando::findOrFail($id);

        $data = $request->validate([
            'nombres' => ['sometimes', 'string'],
            'apellidos' => ['sometimes', 'string'],
            'celular' => ['sometimes', 'string', 'max:9'],
            'fecha_nacimiento' => ['sometimes', 'date','before_or_equal:' . $maxDate],
            'grupo_id' => ['sometimes', 'nullable', 'exists:grupos,id'],
        ]);

        $confirmando->update($data);
        $confirmando->load('grupo');

        return response()->json([
            'message' => 'Confirmando creado con éxito',
            'confirmando' => [
                'nombres' => $confirmando->nombres,
                'apellidos' => $confirmando->apellidos,
            ],
        ], 201);
    }

    public function destroy($id)
    {
        $confirmando = Confirmando::findOrFail($id);
        $confirmando->delete();

        return response()->json(null, 204);
    }
}
