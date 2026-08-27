<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Requisito;
use App\Tenancy\Facades\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class RequisitoController extends Controller
{
    public static function cacheKey(): string
    {
        return 'catalogo.requisitos.'.(Tenant::parroquiaId() ?? 'all');
    }

    private function nombreUnico(?int $ignorarId = null): Unique
    {
        return Rule::unique('requisitos', 'nombre')
            ->where(fn ($q) => $q->where('parroquia_id', Tenant::parroquiaId()))
            ->ignore($ignorarId);
    }

    public function index()
    {
        return Cache::remember(self::cacheKey(), now()->addDay(), function () {
            return Requisito::orderBy('nombre', 'asc')->get();
        });
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255', $this->nombreUnico()],
        ]);

        $requisito = Requisito::create($data);

        Cache::forget(self::cacheKey());

        return response()->json([
            'message' => 'Requisito creado correctamente',
            'requisito' => $requisito,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $requisito = Requisito::findOrFail($id);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255', $this->nombreUnico($requisito->id)],
        ]);

        $requisito->update($data);

        Cache::forget(self::cacheKey());

        return response()->json([
            'message' => 'Requisito actualizado',
            'requisito' => $requisito,
        ]);
    }

    public function destroy($id)
    {
        $requisito = Requisito::findOrFail($id);
        $requisito->delete();

        Cache::forget(self::cacheKey());

        return response()->json(null, 204);
    }
}
