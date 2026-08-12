<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inyecta las cabeceras de seguridad exigidas en CLAUDE.md (sección
 * "Seguridad Estricta") para todas las respuestas de la API: evita que
 * la app sea embebida en iframes ajenos (clickjacking), evita que el
 * navegador infiera un MIME type distinto al declarado, y limita la
 * información de referer enviada a otros orígenes.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
