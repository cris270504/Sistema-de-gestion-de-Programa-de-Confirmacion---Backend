<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            // Cambia esta URL por la URL real de tu Frontend en Vue
            // El frontend recibirá el token y el email por URL
            return config('app.frontend_url')."/reset-password/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        // Los comandos artisan (migrate, seed, tinker, queue:work) corren con la
        // credencial de despliegue, no con la de un usuario final: se marcan como
        // "privilegiados" para las políticas RLS de grupos/confirmandos/apoderados/asistencia
        // definidas en database/migrations/2026_08_11_120000_enable_row_level_security.php.
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            // Global Scope de parroquia: en CLI real (migrate/seed/tinker/queue) no hay
            // request, se corre sin filtro (los seeders acotan con Tenant::set/runFor).
            // En los tests NO se marca privilegiado: así se puede probar el aislamiento.
            $this->app->make(TenantContext::class)->markPrivileged();

            if (config('database.default') === 'pgsql') {
                try {
                    DB::statement("SELECT set_config('app.current_user_privileged', 'true', false)");
                } catch (\Throwable $e) {
                    // La conexión aún no está disponible (p. ej. antes de crear la BD); se ignora.
                }
            }
        }
    }
}
