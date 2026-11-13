<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Initial commercial model (CLAUDE.md §3). All values are editable by the Superadmin afterwards;
 * the seeder never overwrites existing plans.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'starter', 'name' => 'Starter', 'description' => 'For small practices starting to digitise audit files.', 'price_per_user_sen' => 1500, 'sort_order' => 1, 'ai_credits' => 50],
            ['code' => 'standard', 'name' => 'Standard', 'description' => 'Full audit workflow for growing firms.', 'price_per_user_sen' => 2000, 'sort_order' => 2, 'ai_credits' => 200],
            ['code' => 'professional', 'name' => 'Professional', 'description' => 'Advanced AI agents and quality review for established firms.', 'price_per_user_sen' => 3000, 'sort_order' => 3, 'ai_credits' => 500],
        ];

        foreach ($plans as $plan) {
            // ai_credits: PLACEHOLDER monthly AI credits per user (1 credit = 1,000 tokens) — set commercially via Superadmin.
            $credits = $plan['ai_credits'];
            unset($plan['ai_credits']);
            Plan::firstOrCreate(['code' => $plan['code']], [...$plan, 'currency' => 'MYR', 'min_paid_users' => 3, 'ai_credits_per_user' => $credits, 'is_active' => true]);
        }
    }
}
