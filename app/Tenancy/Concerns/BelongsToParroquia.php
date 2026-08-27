<?php

namespace App\Tenancy\Concerns;

use App\Models\Parroquia;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\ParroquiaScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marca un modelo como perteneciente a una parroquia:
 * - Global Scope de filtrado por parroquia del contexto (salvo que el modelo
 *   desactive `shouldApplyParroquiaScope()` — p. ej. User, por su relación con
 *   la resolución de autenticación).
 * - Setea `parroquia_id` automáticamente al crear, desde el contexto (nunca
 *   desde el request; los controladores usan arrays validados que no lo incluyen).
 * - Relación `parroquia()` y scope local `parroquiaActual()`.
 */
trait BelongsToParroquia
{
    public static function bootBelongsToParroquia(): void
    {
        if (static::shouldApplyParroquiaScope()) {
            static::addGlobalScope(new ParroquiaScope);
        }

        static::creating(function ($model) {
            if (empty($model->parroquia_id) && Tenant::parroquiaId() !== null) {
                $model->parroquia_id = Tenant::parroquiaId();
            }
        });
    }

    protected static function shouldApplyParroquiaScope(): bool
    {
        return true;
    }

    public function parroquia(): BelongsTo
    {
        return $this->belongsTo(Parroquia::class);
    }

    /**
     * Filtra explícitamente por la parroquia del contexto (para modelos sin
     * Global Scope, como User).
     */
    public function scopeParroquiaActual(Builder $query): Builder
    {
        return $query->when(
            Tenant::parroquiaId() !== null && ! Tenant::isPrivileged(),
            fn (Builder $q) => $q->where($this->getTable().'.parroquia_id', Tenant::parroquiaId())
        );
    }
}
