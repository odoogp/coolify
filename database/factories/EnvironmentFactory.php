<?php

namespace Database\Factories;

use App\Models\Environment;
use Illuminate\Database\Eloquent\Factories\Factory;

class EnvironmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'role' => Environment::ROLE_STAGING,
            'project_id' => 1,
        ];
    }

    public function production(): static
    {
        return $this->state(fn (): array => [
            'name' => 'production',
            'role' => Environment::ROLE_PRODUCTION,
        ]);
    }

    public function staging(): static
    {
        return $this->state(fn (): array => [
            'role' => Environment::ROLE_STAGING,
        ]);
    }
}
