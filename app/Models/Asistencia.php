<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Asistencia extends Model
{
    protected $table = 'asistencia';
    
    protected $fillable = [
        'reunion_id',
        'estado',
        'asistente_id',
        'asistente_type',
    ];

    public function reunion()
    {
        return $this->belongsTo(Reunion::class);
    }

    public function asistente(): MorphTo
    {
        return $this->morphTo();
    }

}