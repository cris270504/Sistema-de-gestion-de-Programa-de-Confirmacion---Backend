<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tenancy\Facades\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

// Asegúrate de que el nombre de la clase coincida con el nombre del archivo
class PassportAuthController extends Controller
{
    /**
     * Login request.
     */
    public function login(Request $request)
    {
        // Acepta el campo unificado 'login' (correo o DNI). Se mantiene compatibilidad
        // con clientes viejos que aún manden 'dni' o 'email' por separado.
        $request->validate([
            'login' => ['required_without_all:dni,email', 'string', 'max:150'],
            'dni' => ['required_without_all:login,email', 'string', 'max:20'],
            'email' => ['required_without_all:login,dni', 'string', 'email', 'max:150'],
            'password' => ['required', 'string'],
        ]);

        $login = $request->input('login', $request->input('email', $request->input('dni')));
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'dni';

        if (! Auth::attempt([$field => $login, 'password' => $request->input('password')])) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        // Obtenemos el usuario autenticado y cargamos sus grupos + su parroquia
        $user = $request->user()->load(['grupos', 'parroquia']);

        if ($user->activo === false) {
            return response()->json([
                'message' => 'Tu cuenta está desactivada. Contacta al administrador de tu parroquia.',
            ], 403);
        }

        $esProveedor = $user->hasRole('proveedor');

        // El login es ruta pública: ResolveTenant corrió sin usuario, así que fijamos
        // el contexto acá para poder devolver la configuración de la parroquia. El
        // proveedor no está acotado a una parroquia.
        $esProveedor ? Tenant::markPrivileged() : Tenant::set($user->parroquia_id);

        // Revocamos los tokens anteriores: sin esto se acumulan uno por login en
        // oauth_access_tokens (cada uno válido hasta su expiración). Efecto: una
        // sola sesión activa por usuario, consistente con logout() que ya los borra
        // todos.
        $user->tokens()->delete();

        // Creamos el token
        $token = $user->createToken('API Token')->accessToken;

        // Métricas básicas (conteos) en la respuesta del login: el dashboard pinta los
        // números al instante en vez de esperar la llamada a /dashboard/metricas, que
        // además calcula alertas. El proveedor no tiene parroquia => sin métricas.
        $metricas = $esProveedor ? null : DashboardController::metricasBasicas();

        return response()->json([
            'token' => $token,
            'metricas' => $metricas,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'dni' => $user->dni,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'grupo_ids' => $user->grupos->pluck('id'),
                'parroquia' => (! $esProveedor && $user->parroquia) ? [
                    'id' => $user->parroquia->id,
                    'slug' => $user->parroquia->slug,
                    'nombre' => $user->parroquia->nombre,
                ] : null,
            ],
            'configuracion' => Tenant::config(),
        ]);
    }

    /**
     * Devuelve los datos del usuario autenticado.
     */
    public function me(Request $request)
    {
        // 1. Cargamos explícitamente SOLO el 'id' y 'nombre' de la tabla grupos
        $user = $request->user()->load(['grupos:id,nombre', 'parroquia']);
        $esProveedor = $user->hasRole('proveedor');

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'dni' => $user->dni,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'parroquia' => (! $esProveedor && $user->parroquia) ? [
                'id' => $user->parroquia->id,
                'slug' => $user->parroquia->slug,
                'nombre' => $user->parroquia->nombre,
            ] : null,
            'configuracion' => Tenant::config(),

            // 2. Mantenemos el arreglo de IDs por si tu frontend lo usa en otra vista
            'grupo_ids' => $user->grupos->pluck('id'),

            // 3. Limpiamos la data. Si es una relación de muchos-a-muchos (belongsToMany),
            // Laravel inyecta el objeto 'pivot'. Con el map() lo extirpamos para ahorrar bytes.
            'grupos' => $user->grupos->map(function ($grupo) {
                return [
                    'id' => $grupo->id,
                    'nombre' => $grupo->nombre,
                ];
            })->toArray(),
        ]);
    }

    /**
     * Logout the user and revoke the current token.
     */
    public function logout(Request $request)
    {
        try {
            if ($request->user()) {
                Auth::user()->tokens()->delete();

                return response()->json([
                    'response_code' => 200,
                    'status' => 'success',
                    'message' => 'Successfully logged out',
                ]);
            }

            return response()->json([
                'response_code' => 401,
                'status' => 'error',
                'message' => 'User not authenticated',
            ], 401);
        } catch (\Exception $e) {
            Log::error('Logout Error: '.$e->getMessage());

            return response()->json([
                'response_code' => 500,
                'status' => 'error',
                'message' => 'An error occurred during logout',
            ], 500);
        }
    }
}
