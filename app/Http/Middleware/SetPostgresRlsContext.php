<?php

namespace App\Http\Middleware;

use App\Tenancy\Facades\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija en la sesión de Postgres los claims del usuario del request
 * (`request.jwt.claims`) para que las políticas RLS filtren por rol y parroquia.
 *
 * Fase 2 de la migración a Supabase: la RLS lee `request.jwt.claims` (lo mismo
 * que pone PostgREST con el JWT de Supabase Auth). Este middleware pone el MISMO
 * claim, sintético, para las peticiones que todavía sirve Laravel. Así hay UN
 * solo mecanismo de contexto. Ver `2026_09_07_100000_fase2_rls_por_claims`.
 *
 * Corre DESPUÉS de ResolveTenant (la parroquia y el acting-as del proveedor
 * salen de App\Tenancy\TenantContext).
 *
 * `set_config(..., false)` = ámbito de SESIÓN → requiere conexión directa o el
 * **session pooler** de Supabase (`:5432`), NUNCA el transaction pooler (`:6543`).
 */
class SetPostgresRlsContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'SELECT set_config(?, ?, false)',
                ['request.jwt.claims', json_encode($this->claims($request))]
            );
        }

        return $next($request);
    }

    /**
     * Claims sintéticos con la misma forma que produce el Custom Access Token
     * Hook de Supabase (app_user_id / parroquia_id / roles / es_proveedor).
     */
    private function claims(Request $request): object
    {
        $user = $request->user();

        // CLI (artisan/seed/tinker/queue): sin request, corre con la credencial
        // de despliegue → se salta toda la RLS.
        if (! $user) {
            return Tenant::isPrivileged() ? (object) ['es_proveedor' => true] : (object) [];
        }

        $parroquiaId = Tenant::parroquiaId();

        return (object) [
            'sub' => $user->auth_id,
            'app_user_id' => (string) $user->id,
            // Vacío = sin filtro de parroquia (login, público, proveedor global).
            'parroquia_id' => $parroquiaId ? (string) $parroquiaId : null,
            'roles' => $user->getRoleNames()->values()->all(),
            // proveedor SIN parroquia en contexto = global (ve todo). Acotado a
            // una parroquia (X-Parroquia-Id) = solo esa.
            'es_proveedor' => $user->hasRole('proveedor') && $parroquiaId === null,
        ];
    }
}
