<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToParroquia;
use Illuminate\Database\Eloquent\Model;

class FrontendErrorLog extends Model
{
    use BelongsToParroquia;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'message',
        'stack',
        'url',
        'user_agent',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
