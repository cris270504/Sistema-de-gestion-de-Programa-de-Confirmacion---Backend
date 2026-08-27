<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sacramento;
use App\Tenancy\Facades\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SacramentoController extends Controller
{
    /**
     * Clave de caché del catálogo, por parroquia (cada una tiene sus sacramentos).
     */
    public static function cacheKey(): string
    {
        return 'catalogo.sacramentos.'.(Tenant::parroquiaId() ?? 'all');
    }

    public function index()
    {
        return Cache::remember(self::cacheKey(), now()->addDay(), function () {
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

        Cache::forget(self::cacheKey());

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

        Cache::forget(self::cacheKey());

        return response()->json([
            'message' => 'Sacramento actualizado con éxito', // <-- Corregido
            'sacramento' => $sacramento,
        ]);
    }

    public function destroy($id)
    {
        $sacramento = Sacramento::findOrFail($id);
        $sacramento->delete();

        Cache::forget(self::cacheKey());

        return response()->json(null, 204);
    }
}
