<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Propaga el usuario autenticado del request a la sesión de Postgres
 * (app.current_user_id / app.current_user_privileged / app.current_parroquia_id)
 * para que las políticas RLS filtren según el rol y la parroquia reales, no solo
 * según el filtrado en PHP.
 *
 * IMPORTANTE: usa `set_config(..., false)` = ámbito de SESIÓN. Requiere que la
 * conexión de la app sea directa o el **session pooler** de Supabase (host
 * pooler.supabase.com:5432), NUNCA el transaction pooler (:6543), donde la
 * conexión de servidor se reasigna entre transacciones y el contexto se perdería.
 * Con PHP-FPM y PDO::ATTR_PERSISTENT=false la conexión se cierra por request.
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
                $user && $user->hasAnyRole(['coordinador', 'super-admin']) ? 'true' : 'false',
            ]);

            // Parroquia del usuario. Vacío ('') = sin contexto de parroquia (login,
            // endpoints públicos, CLI) -> las políticas de parroquia no filtran.
            DB::statement('SELECT set_config(?, ?, false)', [
                'app.current_parroquia_id',
                $user && $user->parroquia_id ? (string) $user->parroquia_id : '',
            ]);
        }

        return $next($request);
    }
}
