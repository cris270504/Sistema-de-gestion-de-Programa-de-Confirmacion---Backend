<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Confirmando extends Model
{
    use HasFactory;

    protected $fillable = [
        'nombres', 'apellidos', 'celular', 'genero',
        'fecha_nacimiento', 'grupo_id', 'estado',
    ];

    public function grupo()
    {
        return $this->belongsTo(Grupo::class);
    }

    public function apoderados()
    {
        return $this->belongsToMany(Apoderado::class, 'confirmando_apoderado')->withPivot('tipo_apoderado_id');
    }

    public function sacramentos()
    {
        return $this->belongsToMany(Sacramento::class, 'confirmando_sacramento')->withPivot('estado');
    }

    public function requisitos()
    {
        return $this->belongsToMany(Requisito::class, 'confirmando_requisito')->withPivot(['estado', 'fecha_entrega']);
    }

    public function asistencias(): MorphMany
    {
        return $this->morphMany(Asistencia::class, 'asistente');
    }
}
