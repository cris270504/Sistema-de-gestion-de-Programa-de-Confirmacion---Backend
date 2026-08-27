<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToParroquia;
use Illuminate\Database\Eloquent\Model;

class Sacramento extends Model
{
    use BelongsToParroquia;

    protected $fillable = [
        'nombre',
        'clave',
    ];

    public function requisitos()
    {
        return $this->belongsToMany(Requisito::class, 'sacramento_requisito');
    }

    public function confirmandos()
    {
        return $this->belongsToMany(Confirmando::class, 'confirmando_sacramento')->withPivot('estado');
    }
}
