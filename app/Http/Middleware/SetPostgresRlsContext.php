<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Propaga el usuario autenticado del request a la sesión de Postgres
 * (app.current_user_id / app.current_user_privileged) para que las
 * políticas RLS de grupos, confirmandos, apoderados y asistencia
 * puedan filtrar según el rol real, no solo según el filtrado en PHP.
 *
 * Se fija en cada request (no solo cuando hay usuario) para evitar que
 * un valor de un request anterior "sobreviva" si la conexión PDO se
 * reutilizara (p. ej. bajo Octane); con PHP-FPM y PDO::ATTR_PERSISTENT
 * en false (config/database.php) la conexión ya se cierra por request.
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
        }

        return $next($request);
    }
}
