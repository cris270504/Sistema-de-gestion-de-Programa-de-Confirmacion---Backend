<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso si la parroquia del usuario está desactivada (interruptor que
 * maneja el proveedor). El propio proveedor nunca queda bloqueado.
 */
class ParroquiaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->hasRole('proveedor') && $user->parroquia && ! $user->parroquia->activa) {
            return response()->json([
                'message' => 'Esta cuenta está desactivada. Contacta al proveedor del sistema.',
            ], 403);
        }

        return $next($request);
    }
}
