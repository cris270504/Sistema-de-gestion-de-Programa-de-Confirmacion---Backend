<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Parroquia extends Model
{
    use HasFactory;

    protected $table = 'parroquias';

    protected $fillable = [
        'nombre',
        'slug',
        'activa',
        'zona_horaria',
        'contacto_email',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];

    public function configuracion()
    {
        return $this->hasOne(ParroquiaConfiguracion::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function grupos()
    {
        return $this->hasMany(Grupo::class);
    }

    public function confirmandos()
    {
        return $this->hasMany(Confirmando::class);
    }
}
