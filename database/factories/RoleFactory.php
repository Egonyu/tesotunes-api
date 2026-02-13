<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word,
            'display_name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence,
            'is_active' => true,
            'priority' => 1,
        ];
    }

    public function admin(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'admin',
                'display_name' => 'Admin',
                'description' => 'Administrator with full system management',
                'priority' => 5,
            ];
        });
    }

    public function user(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'user',
                'display_name' => 'User',
                'description' => 'Standard user',
                'priority' => 1,
            ];
        });
    }
    
    public function artist(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'artist',
                'display_name' => 'Artist',
                'description' => 'Verified artist',
                'priority' => 2,
            ];
        });
    }
}
