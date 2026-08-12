<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Requisito;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RequisitoController extends Controller
{
    public const CACHE_KEY = 'catalogo.requisitos';

    public function index()
    {
        return Cache::remember(self::CACHE_KEY, now()->addDay(), function () {
            return Requisito::orderBy('nombre', 'asc')->get();
        });
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255|unique:requisitos,nombre'
        ]);

        $requisito = Requisito::create($data);

        Cache::forget(self::CACHE_KEY);

        return response()->json([
            'message' => 'Requisito creado correctamente',
            'requisito' => $requisito
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $requisito = Requisito::findOrFail($id);

        $data = $request->validate([
            'nombre' => 'required|string|max:255|unique:requisitos,nombre,' . $requisito->id
        ]);

        $requisito->update($data);

        Cache::forget(self::CACHE_KEY);

        return response()->json([
            'message' => 'Requisito actualizado',
            'requisito' => $requisito
        ]);
    }

    public function destroy($id)
    {
        $requisito = Requisito::findOrFail($id);
        $requisito->delete();

        Cache::forget(self::CACHE_KEY);

        return response()->json(null, 204);
    }
}