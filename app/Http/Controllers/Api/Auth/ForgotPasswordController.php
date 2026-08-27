<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class ForgotPasswordController extends Controller
{
    public function sendResetLinkEmail(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // Si no hay un transporte de correo real configurado (por defecto Laravel
        // usa 'log', que no envía nada), avisamos explícitamente en vez de decir
        // "te enviamos un enlace" cuando en realidad no salió ningún correo.
        if (in_array(config('mail.default'), ['log', 'array', null, ''], true)) {
            return response()->json([
                'message' => 'El sistema todavía no está configurado para enviar correos. '
                    .'Contacta al administrador para restablecer tu contraseña.',
            ], 503);
        }

        $status = Password::sendResetLink($request->only('email'));

        // Respuesta genérica: no revelamos si el email existe o no (evita enumeración
        // de usuarios). Solo el throttle se informa, para que el usuario sepa esperar.
        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'status' => 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña.',
        ]);
    }
}
