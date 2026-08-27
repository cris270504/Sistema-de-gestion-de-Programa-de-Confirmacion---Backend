<?php

namespace App\Tenancy;

/**
 * Valores por defecto de la configuración de una parroquia. Son los que antes
 * estaban fijos en el código. `TenantContext::config()` devuelve la fila guardada
 * mezclada sobre estos defaults (o solo estos si la parroquia aún no tiene fila).
 */
class TenantConfig
{
    public const DEFAULTS = [
        'programa_inicio' => null,
        'programa_fin' => null,
        'dias_ventana_justificacion' => 21,
        'tipos_reunion' => ['Confirmandos', 'Catequistas', 'Apoderados'],
        'umbrales_alerta' => [
            'alto_injustificadas' => 4,
            'alto_racha' => 2,
            'alto_seguidas_historicas' => 3,
            'medio_justificadas' => 4,
            'bajo_tardanzas_seguidas' => 2,
        ],
        'procedencias' => ['sede', 'caserio'],
        'branding' => [
            'nombre_publico' => null,
            'logo_url' => null,
            'color_primario' => '#2563eb',
        ],
        // Etiquetas visibles para los roles internos. Vacío = se usa el nombre interno.
        'roles_labels' => [],
    ];

    /**
     * Mezcla los valores guardados sobre los defaults. Para los objetos anidados
     * (umbrales_alerta, branding) rellena las claves faltantes con el default; para
     * las listas (tipos_reunion, procedencias) y escalares, el valor guardado
     * reemplaza por completo. Un valor guardado null conserva el default.
     */
    public static function merge(?array $stored): array
    {
        if (! $stored) {
            return self::DEFAULTS;
        }

        $result = self::DEFAULTS;

        foreach (self::DEFAULTS as $key => $default) {
            if (! array_key_exists($key, $stored) || $stored[$key] === null) {
                continue;
            }

            if (in_array($key, ['umbrales_alerta', 'branding'], true) && is_array($stored[$key])) {
                $result[$key] = array_replace($default, array_intersect_key($stored[$key], $default));
            } else {
                $result[$key] = $stored[$key];
            }
        }

        return $result;
    }
}
