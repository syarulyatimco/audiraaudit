<?php

namespace Database\Factories;

use App\Enums\FirmRole;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Firm;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'type' => UserType::Firm,
            'firm_role' => FirmRole::Associate,
            'is_firm_admin' => false,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Password!2345'),
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    public function role(FirmRole $role): static
    {
        return $this->state(fn () => ['firm_role' => $role]);
    }

    public function partner(): static
    {
        return $this->role(FirmRole::Partner);
    }

    public function manager(): static
    {
        return $this->role(FirmRole::Manager);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['is_firm_admin' => true]);
    }

    public function deactivated(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Deactivated]);
    }

    public function superadmin(): static
    {
        return $this->state(fn () => ['type' => UserType::Platform, 'firm_id' => null, 'firm_role' => null]);
    }
}
