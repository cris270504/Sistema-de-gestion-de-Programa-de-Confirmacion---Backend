<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reunion;
use App\Tenancy\Facades\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class ReunionController extends Controller
{
    private function tipoRule(): In
    {
        return Rule::in(Tenant::config()['tipos_reunion']);
    }

    public function index()
    {
        return Reunion::orderBy('fecha', 'asc')->get();
    }

    public function show($id)
    {
        return Reunion::findOrFail($id);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre_tema' => ['required', 'string', 'max:100'],
            'fecha' => ['required', 'date', 'after_or_equal:today'],
            'descripcion' => ['nullable', 'string', 'max:150'],
            'tipo' => ['required', 'string', $this->tipoRule()],
        ]);

        $reunion = Reunion::create($data);

        return response()->json([
            'message' => 'Reunión creada con éxito',
            'reunion' => $reunion,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $reunion = Reunion::findOrFail($id);

        // En edición NO se exige 'after_or_equal:today': debe poder corregirse el tema,
        // la descripción o el tipo de una reunión ya pasada sin tener que moverle la fecha.
        $data = $request->validate([
            'nombre_tema' => ['required', 'string', 'max:100'],
            'fecha' => ['required', 'date'],
            'descripcion' => ['nullable', 'string', 'max:150'],
            'tipo' => ['required', 'string', $this->tipoRule()],
        ]);

        $reunion->update($data);

        return response()->json([
            'message' => 'Reunión actualizada con éxito',
            'reunion' => $reunion,
        ], 200);
    }

    public function destroy($id)
    {
        $reunion = Reunion::findOrFail($id);
        $reunion->delete();

        return response()->json(null, 204);
    }

    public function upcoming()
    {
        $reuniones = Reunion::where('fecha', '>=', now())
            ->orderBy('fecha', 'asc')
            ->take(5)
            ->get();

        return response()->json($reuniones);
    }
}
