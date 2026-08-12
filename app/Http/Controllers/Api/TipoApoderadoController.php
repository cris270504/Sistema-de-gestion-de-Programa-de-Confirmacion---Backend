<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TipoApoderado;
use Illuminate\Support\Facades\Cache;

class TipoApoderadoController extends Controller
{
    public const CACHE_KEY = 'catalogo.tipos_apoderado';

    public function index()
    {
        return Cache::remember(self::CACHE_KEY, now()->addDay(), function () {
            return TipoApoderado::all();
        });
    }
}
