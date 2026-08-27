<?php

namespace App\Tenancy;

use App\Models\ParroquiaConfiguracion;
use Illuminate\Support\Facades\Cache;

/**
 * Contexto de parroquia (tenant) del request/proceso actual. Se registra como
 * singleton, así que su estado vive lo que dura el request (o el comando artisan).
 *
 * - HTTP: el middleware ResolveTenant lo fija desde el usuario autenticado.
 * - CLI (migrate/seed/tinker/queue): se marca privilegiado en AppServiceProvider,
 *   o el seeder fija una parroquia concreta.
 *
 * El Global Scope `ParroquiaScope` filtra por `parroquiaId()` salvo que el contexto
 * esté marcado como privilegiado o que aún no haya parroquia (p. ej. durante el login,
 * antes de saber a qué parroquia pertenece el usuario).
 */
class TenantContext
{
    private ?int $parroquiaId = null;

    private bool $privileged = false;

    public function set(?int $parroquiaId): void
    {
        $this->parroquiaId = $parroquiaId;
    }

    public function parroquiaId(): ?int
    {
        return $this->parroquiaId;
    }

    public function markPrivileged(bool $privileged = true): void
    {
        $this->privileged = $privileged;
    }

    public function isPrivileged(): bool
    {
        return $this->privileged;
    }

    /**
     * ¿Debe el Global Scope filtrar por parroquia ahora mismo?
     */
    public function shouldScope(): bool
    {
        return $this->parroquiaId !== null && ! $this->privileged;
    }

    /**
     * Configuración efectiva de la parroquia actual (defaults + fila guardada),
     * cacheada. Sin contexto de parroquia devuelve solo los defaults.
     */
    public function config(?int $parroquiaId = null): array
    {
        $id = $parroquiaId ?? $this->parroquiaId();

        if ($id === null) {
            return TenantConfig::DEFAULTS;
        }

        return Cache::remember("parroquia.config.$id", now()->addHours(6), function () use ($id) {
            $fila = ParroquiaConfiguracion::withoutGlobalScopes()
                ->where('parroquia_id', $id)
                ->first();

            return TenantConfig::merge($fila?->toConfigArray());
        });
    }

    public function forgetConfig(?int $parroquiaId = null): void
    {
        Cache::forget('parroquia.config.'.($parroquiaId ?? $this->parroquiaId()));
    }

    /**
     * Ejecuta un callback ignorando el filtro de parroquia (para el rol proveedor,
     * onboarding, jobs cross-parroquia, etc.).
     */
    public function runPrivileged(callable $callback): mixed
    {
        $previous = $this->privileged;
        $this->privileged = true;

        try {
            return $callback();
        } finally {
            $this->privileged = $previous;
        }
    }

    /**
     * Ejecuta un callback dentro de una parroquia concreta.
     */
    public function runFor(?int $parroquiaId, callable $callback): mixed
    {
        $previousId = $this->parroquiaId;
        $previousPriv = $this->privileged;
        $this->parroquiaId = $parroquiaId;
        $this->privileged = false;

        try {
            return $callback();
        } finally {
            $this->parroquiaId = $previousId;
            $this->privileged = $previousPriv;
        }
    }
}
