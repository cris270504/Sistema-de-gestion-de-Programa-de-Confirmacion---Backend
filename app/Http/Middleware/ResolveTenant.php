<?php

namespace App\Http\Middleware;

use App\Tenancy\Facades\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija la parroquia del contexto a partir del usuario autenticado. Se añade al
 * grupo `api` después de SetPostgresRlsContext. `$request->user()` se resuelve
 * solo desde el Bearer token (guard `api` de Passport), así que funciona aunque
 * el middleware `auth:api` de la ruta todavía no haya corrido.
 *
 * Si no hay usuario (login, forgot-password, endpoints públicos) el contexto
 * queda sin parroquia y el Global Scope no filtra.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->parroquia_id) {
            Tenant::set($user->parroquia_id);
        }

        return $next($request);
    }
}
