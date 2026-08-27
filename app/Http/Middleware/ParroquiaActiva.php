<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso si:
 *  - la cuenta del usuario está desactivada (`users.activo = false`), o
 *  - la parroquia del usuario está desactivada (interruptor que maneja el proveedor).
 * El propio proveedor nunca queda bloqueado por el estado de una parroquia.
 */
class ParroquiaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->activo === false) {
            return response()->json([
                'message' => 'Tu cuenta está desactivada. Contacta al administrador de tu parroquia.',
            ], 403);
        }

        if ($user && ! $user->hasRole('proveedor') && $user->parroquia && ! $user->parroquia->activa) {
            return response()->json([
                'message' => 'Esta cuenta está desactivada. Contacta al proveedor del sistema.',
            ], 403);
        }

        return $next($request);
    }
}
