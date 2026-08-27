<?php

namespace App\Console\Commands;

use App\Models\Parroquia;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PromoverProveedor extends Command
{
    protected $signature = 'proveedor:promover {email : Email del usuario a promover (se crea si no existe)}';

    protected $description = 'Da el rol `proveedor` (dueño de la plataforma) a un usuario.';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();
        $tempPassword = null;

        if (! $user) {
            $primeraParroquia = Parroquia::query()->orderBy('id')->value('id');
            if (! $primeraParroquia) {
                $this->error('No hay ninguna parroquia. Corre primero las migraciones.');

                return self::FAILURE;
            }

            $tempPassword = Str::password(12);
            $user = User::forceCreate([
                'parroquia_id' => $primeraParroquia,
                'name' => 'Proveedor',
                'email' => $email,
                'password' => $tempPassword,
            ]);
        }

        $user->assignRole('proveedor');

        $this->info("«{$user->email}» ahora es proveedor.");
        if ($tempPassword) {
            $this->warn("Contraseña temporal: {$tempPassword}");
        }

        return self::SUCCESS;
    }
}
