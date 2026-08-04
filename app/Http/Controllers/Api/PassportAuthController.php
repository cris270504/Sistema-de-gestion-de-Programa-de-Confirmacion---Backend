<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        $credentials = $request->validate([
            'dni' => ['required', 'string', 'max:8'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        // Obtenemos el usuario autenticado y cargamos sus grupos
        $user = $request->user()->load('grupos');

        // Creamos el token
        $token = $user->createToken('API Token')->accessToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'dni' => $user->dni,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'grupo_ids' => $user->grupos->pluck('id'),
            ],
        ]);
    }

    /**
     * Devuelve los datos del usuario autenticado.
     */
    public function me(Request $request)
    {
        // 1. Cargamos explícitamente SOLO el 'id' y 'nombre' de la tabla grupos
        $user = $request->user()->load('grupos:id,nombre');

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'dni' => $user->dni,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),

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
