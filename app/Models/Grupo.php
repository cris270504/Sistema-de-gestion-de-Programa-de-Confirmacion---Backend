<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Grupo extends Model
{
    protected $fillable = [
        'nombre',
        'periodo',
        'color',
        'procedencia'
    ];

    public function catequistas()
    {
        return $this->belongsToMany(User::class, 'catequista_grupo', 'grupo_id', 'user_id')
            ->withTimestamps();
    }
    
    public function confirmandos()
    {
        return $this->hasMany(Confirmando::class, 'grupo_id');
    }
}
