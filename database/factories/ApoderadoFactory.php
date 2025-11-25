<?php

namespace Database\Factories;

use App\Models\Apoderado;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApoderadoFactory extends Factory
{
    /**
     * El nombre del modelo correspondiente.
     *
     * @var string
     */
    protected $model = Apoderado::class;

    /**
     * Define el estado por defecto del modelo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombres' => $this->faker->firstName(),
            'apellidos' => $this->faker->lastName() . ' ' . $this->faker->lastName(),
            'celular' => $this->faker->numerify('9########'), // Genera un número de 9 dígitos
        ];
    }
}