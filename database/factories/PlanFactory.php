<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => 'Standard',
            'price_per_user_sen' => 2000,
            'currency' => 'MYR',
            'min_paid_users' => 3,
            'max_users' => null,
            'ai_credits_per_user' => 0,
            'is_active' => true,
            'sort_order' => 1,
        ];
    }
}
