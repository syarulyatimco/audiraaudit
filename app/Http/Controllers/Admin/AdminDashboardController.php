<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FirmStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\Firm;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(BillingService $billing): View
    {
        $firms = Firm::with('currentSubscription.plan')->latest()->get();
        $mrr = $firms->filter(fn (Firm $f) => $f->status === FirmStatus::Active)
            ->sum(fn (Firm $f) => $billing->estimate($f)['monthly_total_sen']);
        $pipeline = $firms->filter(fn (Firm $f) => $f->status === FirmStatus::Trial)
            ->sum(fn (Firm $f) => $billing->estimate($f)['monthly_total_sen']);

        return view('admin.dashboard', [
            'stats' => [
                'firms' => $firms->count(),
                'active' => $firms->where('status', FirmStatus::Active)->count(),
                'trial' => $firms->where('status', FirmStatus::Trial)->count(),
                'suspended' => $firms->where('status', FirmStatus::Suspended)->count(),
                'paid_users' => User::where('type', UserType::Firm->value)->where('status', UserStatus::Active->value)->count(),
                'client_users' => User::where('type', UserType::Client->value)->count(),
                'mrr_sen' => $mrr,
                'trial_pipeline_sen' => $pipeline,
            ],
            'recentFirms' => $firms->take(8),
            'aiConfigured' => app(\App\Ai\Gateway\ModelRouter::class)->isConfigured(),
            'aiProvider' => config('ai.default_provider'),
            'aiEnabledFirms' => $firms->where('ai_enabled', true)->count(),
            'aiUsage' => \App\Models\AiUsage::query()->where('ai_usage.created_at', '>=', now(config('audira.display_timezone'))->startOfMonth()->utc())
                ->join('firms', 'firms.id', '=', 'ai_usage.firm_id')->groupBy('firms.name')
                ->selectRaw('firms.name, sum(ai_usage.credits) as credits, sum(ai_usage.input_tokens + ai_usage.output_tokens) as tokens')
                ->orderByDesc('credits')->limit(10)->get(),
            'analytics' => [
                'outstanding_sen' => (int) \App\Models\Invoice::where('status', 'issued')->sum('total_sen'),
                'overdue' => \App\Models\Invoice::where('status', 'issued')->whereDate('due_on', '<', now()->toDateString())->count(),
                'collected_month_sen' => (int) \App\Models\Invoice::where('status', 'paid')->where('paid_at', '>=', now(config('audira.display_timezone'))->startOfMonth()->utc())->sum('total_sen'),
                'past_due_firms' => \App\Models\Subscription::where('status', 'past_due')->count(),
                // Counts only — no client names or audit content cross the tenant boundary.
                'engagements' => \App\Models\Engagement::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
                'signed_month' => \App\Models\AuditReport::where('status', 'signed')->where('signed_at', '>=', now(config('audira.display_timezone'))->startOfMonth()->utc())->count(),
                'ai_runs' => \App\Models\AiRun::where('created_at', '>=', now(config('audira.display_timezone'))->startOfMonth()->utc())->selectRaw('agent, count(*) as n')->groupBy('agent')->orderByDesc('n')->pluck('n', 'agent')->all(),
                'support_grants' => \App\Models\SupportAccessGrant::active()->count(),
            ],
            'plans' => Plan::orderBy('sort_order')->withCount(['subscriptions as live_subscriptions' => fn ($q) => $q->whereIn('status', ['trialing', 'active', 'past_due'])])->get(),
        ]);
    }
}
