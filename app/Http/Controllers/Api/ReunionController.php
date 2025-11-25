<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reunion;

class ReunionController extends Controller
{
    public function index()
    {
        // Retornamos todas las reuniones ordenadas por fecha
        return Reunion::orderBy('fecha', 'asc')->get();
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
