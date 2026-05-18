<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Justificacion extends Model
{
    use HasFactory;

    protected $table = 'justificaciones';

    protected $fillable = [
        'asistencia_id',
        'motivo',
        'descripcion',
        'estado'
    ];

    // Relación con la asistencia original
    public function asistencia()
    {
        return $this->belongsTo(Asistencia::class);
    }
}