<?php

namespace Database\Factories;

use App\Models\Parroquia;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    /**
     * parroquia_id (NOT NULL) normalmente lo pone el trait BelongsToParroquia desde el
     * contexto. Como red de seguridad para tests que aún no fijan el contexto, si el
     * modelo llega sin parroquia le asignamos una existente (o una nueva).
     */
    public function configure(): static
    {
        return $this->afterMaking(function ($user) {
            if (! $user->parroquia_id) {
                $user->parroquia_id = Tenant::parroquiaId()
                    ?? (Parroquia::query()->value('id') ?? Parroquia::factory()->create()->id);
            }
        });
    }

    public function definition(): array
    {
        return [
            // dni es char(8) NOT NULL UNIQUE (ver create_users_table) — sin esto, cualquier
            // test que haga User::factory()->create() sin especificarlo falla con
            // "NOT NULL constraint failed: users.dni".
            'dni' => fake()->unique()->numerify('########'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
