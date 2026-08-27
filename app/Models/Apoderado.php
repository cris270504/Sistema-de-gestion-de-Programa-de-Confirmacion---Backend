<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToParroquia;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Apoderado extends Model
{
    use BelongsToParroquia, HasFactory;

    protected $fillable = [
        'nombres',
        'apellidos',
        'celular',
    ];

    public function confirmandos()
    {
        return $this->belongsToMany(Confirmando::class, 'confirmando_apoderado')->withPivot('tipo_apoderado_id');
    }

    public function asistencias(): MorphMany
    {
        return $this->morphMany(Asistencia::class, 'asistente');
    }
}
