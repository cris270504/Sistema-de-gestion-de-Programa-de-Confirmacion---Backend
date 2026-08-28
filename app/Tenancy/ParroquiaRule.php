<?php

namespace App\Tenancy;

use App\Tenancy\Facades\Tenant;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Reglas de validación acotadas a la parroquia del contexto.
 *
 * La regla `exists:` de Laravel usa el query builder crudo, así que ignora el
 * Global Scope de parroquia. En prod la RLS de Postgres igual la filtra, pero
 * como defensa en profundidad (y para que dev/mariadb se comporte igual) estas
 * reglas añaden el `where parroquia_id` explícito.
 */
class ParroquiaRule
{
    /**
     * `exists` en una tabla con columna `parroquia_id`, acotado a la parroquia
     * actual. Sin contexto de parroquia (proveedor sin acotar, CLI) se comporta
     * como un `exists` normal.
     */
    public static function exists(string $table, string $column = 'id'): Exists
    {
        $rule = Rule::exists($table, $column);

        $parroquiaId = Tenant::parroquiaId();
        if ($parroquiaId !== null) {
            $rule->where('parroquia_id', $parroquiaId);
        }

        return $rule;
    }
}
