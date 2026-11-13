<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FirmStatus;
use App\Http\Controllers\Controller;
use App\Models\Firm;
use App\Models\Plan;
use App\Services\Billing\BillingService;
use App\Support\Audit\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Platform-level firm administration. Exposes firm metadata and subscription only — never
 * clients, engagements or audit evidence (docs/SECURITY.md).
 */
class FirmAdminController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(FirmStatus::class)],
        ]);

        $firms = Firm::with('currentSubscription.plan')
            ->withCount(['staff as active_staff_count' => fn ($q) => $q->where('status', 'active')])
            ->when($filters['q'] ?? null, fn ($q, $t) => $q->where('name', 'ilike', '%'.addcslashes($t, '%_\\').'%'))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.firms.index', compact('firms', 'filters'));
    }

    public function show(Firm $firm, BillingService $billing): View
    {
        return view('admin.firms.show', [
            'firm' => $firm,
            'estimate' => $billing->estimate($firm),
            'staff' => $firm->staff()->orderBy('name')->get(['id', 'name', 'email', 'firm_role', 'is_firm_admin', 'status', 'last_login_at']),
            'plans' => Plan::where('is_active', true)->orderBy('sort_order')->get(),
            'subscriptions' => $firm->subscriptions()->with('plan')->latest()->get(),
            'invoices' => \App\Models\Invoice::where('firm_id', $firm->id)->latest('issued_at')->limit(12)->get(),
        ]);
    }

    public function suspend(Request $request, Firm $firm, ActivityLogger $logger): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        abort_if($firm->isSuspended(), 422, 'Firm is already suspended.');

        DB::transaction(function () use ($firm, $data, $request, $logger) {
            $firm->update(['status' => FirmStatus::Suspended, 'suspended_reason' => $data['reason']]);
            $logger->log('platform.firm_suspended', "Firm {$firm->name} suspended", $firm, ['reason' => $data['reason']], $request->user(), $firm->id);
        });

        return back()->with('status', "{$firm->name} suspended.");
    }

    public function reactivate(Request $request, Firm $firm, ActivityLogger $logger): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([FirmStatus::Trial->value, FirmStatus::Active->value])]]);

        DB::transaction(function () use ($firm, $data, $request, $logger) {
            $firm->update(['status' => $data['status'], 'suspended_reason' => null]);
            $logger->log('platform.firm_reactivated', "Firm {$firm->name} set to {$data['status']}", $firm, [], $request->user(), $firm->id);
        });

        return back()->with('status', "{$firm->name} is now {$data['status']}.");
    }

    public function changePlan(Request $request, Firm $firm, BillingService $billing): RedirectResponse
    {
        $data = $request->validate(['plan_id' => ['required', Rule::exists('plans', 'id')->where('is_active', true)]]);
        $billing->changePlan($firm, Plan::findOrFail($data['plan_id']), $request->user());

        return back()->with('status', 'Plan changed.');
    }
}
