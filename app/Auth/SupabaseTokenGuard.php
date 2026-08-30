<?php

namespace App\Auth;

use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve el usuario autenticado a partir de un JWT de Supabase Auth (GoTrue).
 *
 * Fase 1 de la migración a Supabase: el frontend ya se autentica con supabase-js
 * y manda el access token de Supabase. Este guard lo valida y devuelve el
 * `App\Models\User` enlazado por `users.auth_id`, para que TODO lo que sigue
 * (Spatie `permission:`, ResolveTenant, SetPostgresRlsContext) siga funcionando
 * mientras Laravel aún sirve los datos.
 *
 * Supabase firma los tokens con **claves asimétricas (ES256)** y publica la clave
 * pública en `{SUPABASE_URL}/auth/v1/.well-known/jwks.json`. Se admite además
 * HS256 con `SUPABASE_JWT_SECRET` como fallback para proyectos aún en el esquema
 * legacy.
 *
 * Se registra con Auth::viaRequest('supabase', ...) en AppServiceProvider.
 */
class SupabaseTokenGuard
{
    /** Memo en proceso del JWKS parseado. */
    private static ?array $jwks = null;

    public function __invoke(Request $request): ?User
    {
        $token = $request->bearerToken();

        if (! $token) {
            return null;
        }

        $claims = $this->verificar($token);

        if (! $claims) {
            return null;
        }

        // GoTrue emite aud = "authenticated" para sesiones de usuario.
        if (($claims['aud'] ?? null) !== 'authenticated') {
            return null;
        }

        $authId = $claims['sub'] ?? null;

        if (! $authId) {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()->where('auth_id', $authId)->first();

        if (! $user || $user->activo === false) {
            return null;
        }

        return $user;
    }

    /**
     * Devuelve los claims si la firma y la expiración son válidas; null si no.
     */
    private function verificar(string $token): ?array
    {
        // 1. ES256 vía JWKS (esquema actual de Supabase).
        try {
            $keys = $this->jwks();

            if ($keys) {
                return (array) JWT::decode($token, $keys);
            }
        } catch (\Throwable $e) {
            // cae al fallback HS256
        }

        // 2. HS256 con secreto compartido (legacy).
        $secret = config('services.supabase.jwt_secret');

        if ($secret) {
            try {
                return (array) JWT::decode($token, new Key($secret, 'HS256'));
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * JWKS de Supabase, cacheado 1 h (la clave rota rara vez y php-jwt elige por kid).
     *
     * @return array<string, Key>|null
     */
    private function jwks(): ?array
    {
        if (self::$jwks !== null) {
            return self::$jwks ?: null;
        }

        $url = rtrim((string) config('services.supabase.url'), '/');

        if (! $url) {
            return self::$jwks = [] ?: null;
        }

        $raw = Cache::remember('supabase:jwks', now()->addHour(), function () use ($url) {
            try {
                $res = Http::timeout(4)->get("{$url}/auth/v1/.well-known/jwks.json");

                return $res->successful() ? $res->json() : null;
            } catch (\Throwable $e) {
                Log::warning('No se pudo obtener el JWKS de Supabase: '.$e->getMessage());

                return null;
            }
        });

        if (! is_array($raw) || empty($raw['keys'])) {
            self::$jwks = [];

            return null;
        }

        self::$jwks = JWK::parseKeySet($raw);

        return self::$jwks;
    }
}
