<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToParroquia;
use Illuminate\Database\Eloquent\Model;

class TipoApoderado extends Model
{
    use BelongsToParroquia;

    protected $fillable = ['nombre'];
}
