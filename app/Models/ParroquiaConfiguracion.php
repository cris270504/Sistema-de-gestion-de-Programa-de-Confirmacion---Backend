<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToParroquia;
use Illuminate\Database\Eloquent\Model;

class ParroquiaConfiguracion extends Model
{
    use BelongsToParroquia;

    protected $table = 'parroquia_configuraciones';

    protected $fillable = [
        'parroquia_id',
        'programa_inicio',
        'programa_fin',
        'dias_ventana_justificacion',
        'tipos_reunion',
        'umbrales_alerta',
        'procedencias',
        'branding',
    ];

    protected $casts = [
        'programa_inicio' => 'date:Y-m-d',
        'programa_fin' => 'date:Y-m-d',
        'dias_ventana_justificacion' => 'integer',
        'tipos_reunion' => 'array',
        'umbrales_alerta' => 'array',
        'procedencias' => 'array',
        'branding' => 'array',
    ];

    /**
     * Representación plana para consumir como configuración efectiva.
     */
    public function toConfigArray(): array
    {
        return [
            'programa_inicio' => $this->programa_inicio?->toDateString(),
            'programa_fin' => $this->programa_fin?->toDateString(),
            'dias_ventana_justificacion' => $this->dias_ventana_justificacion,
            'tipos_reunion' => $this->tipos_reunion,
            'umbrales_alerta' => $this->umbrales_alerta,
            'procedencias' => $this->procedencias,
            'branding' => $this->branding,
        ];
    }
}
