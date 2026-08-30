<?php

namespace App\Providers;

use App\Auth\SupabaseTokenGuard;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

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
        // El proveedor (dueño de la plataforma) tiene acceso a todo: cualquier
        // chequeo de permiso (incluido el middleware `permission:`) pasa para él.
        Gate::before(fn ($user) => $user->hasRole('proveedor') ? true : null);

        // Guard `supabase`: valida el access token de Supabase Auth (Fase 1 de la
        // migración). El frontend ya se autentica con supabase-js; Laravel sigue
        // sirviendo los datos validando ese token contra users.auth_id.
        Auth::viaRequest('supabase', fn ($request) => app(SupabaseTokenGuard::class)($request));

        // Expiración de tokens Passport. Sin esto, el default deja tokens válidos
        // ~1 año; combinado con el guardado en localStorage del frontend, un token
        // robado (XSS) serviría por un año. El login usa createToken() = personal
        // access token, así que ese es el que importa.
        Passport::personalAccessTokensExpireIn(Carbon::now()->addDays(30));
        Passport::tokensExpireIn(Carbon::now()->addDays(30));
        Passport::refreshTokensExpireIn(Carbon::now()->addDays(45));

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
