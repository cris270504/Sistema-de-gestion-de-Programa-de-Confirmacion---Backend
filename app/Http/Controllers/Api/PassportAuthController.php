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
            'dni'    => ['required', 'string','max:8'],
            'password' => ['required','string'],
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        // Obtenemos el usuario autenticado
        $user = $request->user();

        // Creamos el token
        $token = $user->createToken('API Token')->accessToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'          => $user->id,
                'name'        => $user->name,
                'dni'         => $user->dni,
                'email'       => $user->email,
                'roles'       => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'grupo_id'    => $user->grupo_id,
            ],
        ]);
    }

    /**
     * Devuelve los datos del usuario autenticado.
     */
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'dni'         => $user->dni,
            'roles'       => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
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