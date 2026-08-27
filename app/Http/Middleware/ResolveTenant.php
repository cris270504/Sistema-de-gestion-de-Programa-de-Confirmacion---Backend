<?php

namespace App\Http\Middleware;

use App\Models\Parroquia;
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
 * El rol `proveedor` (dueño de la plataforma) NO queda acotado a una parroquia:
 * ve todo. Puede acotarse a una parroquia concreta para soporte pasando
 * `?parroquia_id=` o la cabecera `X-Parroquia-Id`.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->hasRole('proveedor')) {
            $actuarComo = $request->header('X-Parroquia-Id') ?? $request->query('parroquia_id');

            if ($actuarComo && Parroquia::whereKey($actuarComo)->exists()) {
                Tenant::set((int) $actuarComo);
            } else {
                Tenant::markPrivileged(); // sin filtro de parroquia
            }

            return $next($request);
        }

        if ($user->parroquia_id) {
            Tenant::set($user->parroquia_id);
        }

        return $next($request);
    }
}
