<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToParroquia;
use Illuminate\Database\Eloquent\Model;

class Reunion extends Model
{
    use BelongsToParroquia;

    protected $fillable = [
        'nombre_tema',
        'fecha',
        'descripcion',
        'tipo',
    ];

    protected $casts = [
        'nombre_tema' => 'string',
        'fecha' => 'datetime',
        'descripcion' => 'string',
        'tipo' => 'string',
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
