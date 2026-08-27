<?php

namespace App\Tenancy;

use App\Tenancy\Facades\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtra automáticamente por la parroquia del contexto actual. Es una comodidad de
 * DX; la frontera de seguridad real es la RLS de Postgres (Fase B del plan) más los
 * filtros explícitos de los controladores.
 */
class ParroquiaScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Tenant::shouldScope()) {
            $builder->where($model->getTable().'.parroquia_id', Tenant::parroquiaId());
        }
    }
}
