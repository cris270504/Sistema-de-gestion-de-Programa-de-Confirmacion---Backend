<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Grupo extends Model
{

    protected $fillable = [
        'nombre',
        'periodo',
    ];

    public function catequistas()
    {
        return $this->hasMany(User::class, 'grupo_id');
    }

    public function confirmandos()
    {
        return $this->hasMany(Confirmando::class, 'grupo_id');
    }
}
