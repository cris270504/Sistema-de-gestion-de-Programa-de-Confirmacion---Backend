<?php

namespace Database\Factories;

use App\Models\Parroquia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ParroquiaFactory extends Factory
{
    protected $model = Parroquia::class;

    public function definition(): array
    {
        $nombre = 'Parroquia '.$this->faker->unique()->lastName();

        return [
            'nombre' => $nombre,
            'slug' => Str::slug($nombre).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'activa' => true,
            'zona_horaria' => 'America/Lima',
            'contacto_email' => null,
        ];
    }
}
