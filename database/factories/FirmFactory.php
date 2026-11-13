<?php

namespace Database\Factories;

use App\Enums\FirmStatus;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Firm> */
class FirmFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company().' PLT',
            'registration_no' => (string) fake()->numerify('2020########'),
            'audit_firm_no' => 'AF '.fake()->numerify('####'),
            'email' => fake()->companyEmail(),
            'status' => FirmStatus::Active,
            'default_framework' => 'mpers',
            'trial_ends_at' => now()->addDays(30),
        ];
    }
}
