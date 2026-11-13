<?php

namespace Database\Factories;

use App\Enums\ClientStatus;
use App\Enums\EntityType;
use App\Enums\ReportingFramework;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Sdn. Bhd.',
            'registration_no' => (string) fake()->unique()->numerify('2019010#####'),
            'entity_type' => EntityType::PrivateCompany,
            'reporting_framework' => ReportingFramework::Mpers,
            'industry' => 'Trading',
            'fye_month' => 12,
            'fye_day' => 31,
        ];
    }

    /** Create the client inside a given firm, attributed to a firm user. */
    public function forUser(User $user): static
    {
        return $this->state(fn () => ['created_by' => $user->id])
            ->afterMaking(fn (Client $c) => $c->firm_id = $user->firm_id);
    }

    public function accepted(): static
    {
        return $this->afterMaking(function (Client $c) {
            $c->status = ClientStatus::Accepted;
            $c->accepted_at = now();
            $c->independence_confirmed = true;
        });
    }
}
