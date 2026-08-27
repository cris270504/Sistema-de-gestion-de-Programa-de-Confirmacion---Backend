<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Tenancy\Concerns\BelongsToParroquia;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToParroquia, HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'celular',
        'email',
        'password',
        'dni',
        'fecha_nacimiento',
    ];

    protected $guard_name = 'api';

    protected $appends = ['grupo_ids'];

    /**
     * User NO lleva Global Scope de parroquia: la resolución de autenticación
     * (retrieveById, Auth::attempt, Password::sendResetLink) debe poder encontrar
     * usuarios de cualquier parroquia. El filtrado se hace explícito en los
     * controladores con ->parroquiaActual().
     */
    protected static function shouldApplyParroquiaScope(): bool
    {
        return false;
    }

    public function grupos()
    {
        return $this->belongsToMany(Grupo::class, 'catequista_grupo', 'user_id', 'grupo_id');
    }

    public function getGrupoIdsAttribute()
    {
        return $this->grupos->pluck('id');
    }

    public function reunionesAsignadas()
    {
        return $this->belongsToMany(Reunion::class, 'reunion_user');
    }

    public function asistencias(): MorphMany
    {
        return $this->morphMany(Asistencia::class, 'asistente');
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }
}
