<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reunion extends Model
{
    protected $fillable = [
        'nombre_tema',
        'fecha',
        'descripcion',
        'tipo',
    ];

    public function expositores()
    {
        return $this->belongsToMany(User::class, 'reunion_user');
    }

    public function asistencias()
    {
        return $this->hasMany(Asistencia::class);
    }
}
