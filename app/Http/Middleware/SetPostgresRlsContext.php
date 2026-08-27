<?php

namespace App\Http\Middleware;

use App\Tenancy\Facades\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Propaga a la sesión de Postgres el usuario y la parroquia del request
 * (app.current_user_id / app.current_user_privileged / app.current_parroquia_id)
 * para que las políticas RLS filtren según el rol y la parroquia reales.
 *
 * Corre DESPUÉS de ResolveTenant: la parroquia y el "privilegiado" salen de
 * App\Tenancy\TenantContext (que ya contempló el rol proveedor y el acting-as).
 *
 * IMPORTANTE: usa `set_config(..., false)` = ámbito de SESIÓN. Requiere que la
 * conexión de la app sea directa o el **session pooler** de Supabase
 * (pooler.supabase.com:5432), NUNCA el transaction pooler (:6543).
 */
class SetPostgresRlsContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $user = $request->user();

            DB::statement('SELECT set_config(?, ?, false)', [
                'app.current_user_id',
                $user ? (string) $user->id : '',
            ]);

            DB::statement('SELECT set_config(?, ?, false)', [
                'app.current_user_privileged',
                ($user && $user->hasAnyRole(['coordinador', 'super-admin', 'proveedor'])) || Tenant::isPrivileged()
                    ? 'true' : 'false',
            ]);

            // Parroquia del contexto. Vacío = sin filtro (login, público, proveedor
            // sin acotar).
            DB::statement('SELECT set_config(?, ?, false)', [
                'app.current_parroquia_id',
                Tenant::parroquiaId() ? (string) Tenant::parroquiaId() : '',
            ]);
        }

        return $next($request);
    }
}
