<?php

namespace App\Ai\Runtime;

use App\Models\AiUsage;
use App\Models\Firm;
use App\Services\Billing\BillingService;
use Illuminate\Support\Carbon;

/**
 * Monthly AI credit allowance = plan credits per user × billable users. Calendar month in the
 * firm display timezone (Malaysia).
 */
class AiBudget
{
    public function __construct(private BillingService $billing) {}

    public function allowance(Firm $firm): int
    {
        $estimate = $this->billing->estimate($firm);

        return (int) (($estimate['plan']?->ai_credits_per_user ?? 0) * $estimate['billable_users']);
    }

    public function used(Firm $firm): int
    {
        $start = Carbon::now(config('audira.display_timezone'))->startOfMonth()->utc();

        return (int) AiUsage::where('firm_id', $firm->id)->where('created_at', '>=', $start)->sum('credits');
    }

    public function remaining(Firm $firm): int
    {
        return max(0, $this->allowance($firm) - $this->used($firm));
    }

    public static function creditsFor(int $tokens): int
    {
        return (int) ceil($tokens / max(1, (int) config('ai.tokens_per_credit')));
    }
}
