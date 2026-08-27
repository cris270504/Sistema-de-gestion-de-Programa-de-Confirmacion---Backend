<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToParroquia;
use Illuminate\Database\Eloquent\Model;

class Requisito extends Model
{
    use BelongsToParroquia;

    protected $fillable = ['nombre'];

    public function sacramentos()
    {
        return $this->belongsToMany(Sacramento::class, 'sacramento_requisito');
    }

    public function confirmandos()
    {
        return $this->belongsToMany(Confirmando::class, 'confirmando_requisito')->withPivot('estado', 'fecha_entrega');
    }
}
