<?php

namespace App\Services\Firm;

use App\Enums\FirmRole;
use App\Enums\FirmStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Firm;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Audit\ActivityLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class FirmRegistrationService
{
    public function __construct(private TenantContext $tenant, private ActivityLogger $logger) {}

    /**
     * Create a firm on a trial subscription with its first user (partner + firm admin).
     * Runs in system mode because the tenant does not exist yet (docs/SECURITY.md §2).
     *
     * @param  array{firm_name: string, registration_no?: ?string, audit_firm_no?: ?string, name: string, email: string, password: string, plan_id: int}  $data
     */
    public function register(array $data): User
    {
        return $this->tenant->runAsSystem(fn () => DB::transaction(function () use ($data) {
            $plan = Plan::where('is_active', true)->findOrFail($data['plan_id']);
            $trialEnds = now()->addDays(config('audira.trial_days'));

            $firm = Firm::create([
                'name' => $data['firm_name'],
                'registration_no' => $data['registration_no'] ?? null,
                'audit_firm_no' => $data['audit_firm_no'] ?? null,
                'email' => $data['email'],
                'status' => FirmStatus::Trial,
                'trial_ends_at' => $trialEnds,
            ]);

            Subscription::create([
                'firm_id' => $firm->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Trialing,
                'price_per_user_sen' => $plan->price_per_user_sen,
                'min_paid_users' => $plan->min_paid_users,
                'current_period_start' => now(),
                'current_period_end' => $trialEnds,
            ]);

            $user = User::create([
                'firm_id' => $firm->id,
                'type' => UserType::Firm,
                'firm_role' => FirmRole::Partner,
                'is_firm_admin' => true,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'status' => UserStatus::Active,
            ]);

            $this->logger->log('firm.registered', "Firm {$firm->name} registered on {$plan->name} trial", $firm, ['plan' => $plan->code], $user, $firm->id);

            return $user;
        }));
    }
}
