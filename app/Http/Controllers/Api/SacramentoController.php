<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sacramento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SacramentoController extends Controller
{
    public const CACHE_KEY = 'catalogo.sacramentos';

    public function index()
    {
        return Cache::remember(self::CACHE_KEY, now()->addDay(), function () {
            return Sacramento::with('requisitos')->latest()->get();
        });
    }

    public function show($id)
    {
        return Sacramento::with('requisitos')->findOrFail($id);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'nombre' => ['required', 'string', 'max:100', 'unique:sacramentos,nombre'], // Agregué unique por seguridad
        ]);

        $sacramento = Sacramento::create([
            'nombre' => $validatedData['nombre'],
        ]);

        Cache::forget(self::CACHE_KEY);

        return response()->json([
            'message' => 'Sacramento creado con éxito',
            'sacramento' => $sacramento, // Devuelve el objeto completo (es más fácil)
        ], 201);
    }

    // Ejemplo de lo que deberías tener en tu Backend (Laravel)
    public function update(Request $request, $id)
    {
        $sacramento = Sacramento::findOrFail($id);
        $sacramento->update($request->only('nombre')); // Actualiza nombre

        // ESTA LÍNEA ES LA CLAVE: Sincronizar la tabla pivote
        if ($request->has('requisitos')) {
            $sacramento->requisitos()->sync($request->requisitos);
        }

        Cache::forget(self::CACHE_KEY);

        return response()->json([
            'message' => 'Sacramento actualizado con éxito', // <-- Corregido
            'sacramento' => $sacramento,
        ]);
    }

    public function destroy($id)
    {
        $sacramento = Sacramento::findOrFail($id);
        $sacramento->delete();

        Cache::forget(self::CACHE_KEY);

        return response()->json(null, 204);
    }
}
