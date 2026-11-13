<?php

namespace App\Services\Billing;

use App\Enums\FirmStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\Firm;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Audit\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Per-user billing: billable seats = max(active paid users, plan minimum).
 * Client portal users are free and never counted.
 */
class BillingService
{
    public function __construct(private ActivityLogger $logger) {}

    public function activePaidUsers(Firm $firm): int
    {
        return $firm->staff()->where('status', UserStatus::Active->value)->count();
    }

    /**
     * @return array{plan: ?Plan, active_users: int, billable_users: int, price_per_user_sen: int, monthly_total_sen: int, min_paid_users: int, max_users: ?int}
     */
    public function estimate(Firm $firm): array
    {
        $subscription = $firm->currentSubscription()->with('plan')->first();
        $active = $this->activePaidUsers($firm);
        $min = $subscription?->min_paid_users ?? 0;
        $price = $subscription?->price_per_user_sen ?? 0;
        $billable = max($active, $min);

        return [
            'plan' => $subscription?->plan,
            'subscription' => $subscription,
            'active_users' => $active,
            'billable_users' => $billable,
            'price_per_user_sen' => $price,
            'monthly_total_sen' => $billable * $price,
            'min_paid_users' => $min,
            'max_users' => $subscription?->plan?->max_users,
        ];
    }

    /** Whether the firm can add one more staff seat (active users + pending invitations). */
    public function hasSeatAvailable(Firm $firm): bool
    {
        $max = $firm->currentSubscription()->with('plan')->first()?->plan?->max_users;
        if ($max === null) {
            return true;
        }

        $pending = Invitation::query()->where('firm_id', $firm->id)->pending()->count();

        return $this->activePaidUsers($firm) + $pending < $max;
    }

    /**
     * Superadmin: move a firm to another plan. The new subscription snapshots the plan's current
     * price so later price edits never apply retroactively.
     */
    public function changePlan(Firm $firm, Plan $plan, User $actor): Subscription
    {
        return DB::transaction(function () use ($firm, $plan, $actor) {
            $current = $firm->currentSubscription()->first();
            $status = $firm->status === FirmStatus::Trial ? SubscriptionStatus::Trialing : SubscriptionStatus::Active;

            if ($current) {
                $current->status = SubscriptionStatus::Cancelled;
                $current->cancelled_at = now();
                $current->save();
            }

            $subscription = Subscription::create([
                'firm_id' => $firm->id,
                'plan_id' => $plan->id,
                'status' => $status,
                'price_per_user_sen' => $plan->price_per_user_sen,
                'min_paid_users' => $plan->min_paid_users,
                'current_period_start' => now(),
                'current_period_end' => $firm->status === FirmStatus::Trial && $firm->trial_ends_at ? $firm->trial_ends_at : now()->addMonth(),
            ]);

            $this->logger->log('subscription.plan_changed', "Plan changed to {$plan->name}", $firm, [
                'from_plan_id' => $current?->plan_id, 'to_plan_id' => $plan->id,
            ], $actor, $firm->id);

            return $subscription;
        });
    }
}
