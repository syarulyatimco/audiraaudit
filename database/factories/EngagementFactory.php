<?php

namespace Database\Factories;

use App\Enums\EngagementType;
use App\Enums\ReportingFramework;
use App\Models\Engagement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Engagement> */
class EngagementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'ENG-'.fake()->unique()->numerify('2025-####'),
            'name' => 'Statutory audit FYE 2025',
            'engagement_type' => EngagementType::StatutoryAudit,
            'reporting_framework' => ReportingFramework::Mpers,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'reporting_deadline' => '2026-06-30',
        ];
    }
}
