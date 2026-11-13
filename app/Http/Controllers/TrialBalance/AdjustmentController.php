<?php

namespace App\Http\Controllers\TrialBalance;

use App\Enums\AdjustmentType;
use App\Http\Controllers\Controller;
use App\Models\Adjustment;
use App\Models\Engagement;
use App\Services\TrialBalance\AdjustmentService;
use App\Services\TrialBalance\LeadScheduleService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdjustmentController extends Controller
{
    public function __construct(private AdjustmentService $service) {}

    public function index(Request $request, Engagement $engagement): View
    {
        Gate::authorize('view', $engagement);

        return view('trial-balance.adjustments', [
            'engagement' => $engagement->load('client'),
            'adjustments' => $engagement->adjustments()->with(['lines', 'preparer', 'poster'])->orderBy('type')->orderBy('number')->get(),
            'canPrepare' => $this->can($request, $engagement, 'engagement.prepare_adjustments'),
        ]);
    }

    public function create(Request $request, Engagement $engagement, LeadScheduleService $schedules): View
    {
        Gate::authorize('view', $engagement);
        abort_unless($this->can($request, $engagement, 'engagement.prepare_adjustments'), 403);

        return view('trial-balance.adjustment-form', [
            'engagement' => $engagement->load('client'),
            'adjustment' => null,
            'accounts' => $schedules->accounts($engagement)->filter(fn ($a) => $a['in_cy']),
        ]);
    }

    public function store(Request $request, Engagement $engagement): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($this->can($request, $engagement, 'engagement.prepare_adjustments'), 403);
        $data = $this->validated($request, true);
        $adj = $this->service->create($engagement, $data, $request->user());

        return redirect()->route('adjustments.show', [$engagement, $adj])->with('status', "{$adj->number} saved as draft.");
    }

    public function show(Request $request, Engagement $engagement, Adjustment $adjustment, LeadScheduleService $schedules): View
    {
        Gate::authorize('view', $engagement);
        $adjustment->load(['lines', 'preparer', 'poster', 'voider', 'approvals.requester', 'approvals.decider', 'findings']);
        $u = $request->user();
        $pending = $adjustment->approvals->firstWhere('status', \App\Enums\ApprovalStatus::Pending);
        $threshold = $this->service->materialThreshold($engagement);

        return view('trial-balance.adjustment', [
            'engagement' => $engagement->load('client'),
            'adjustment' => $adjustment,
            'accountNames' => $schedules->accounts($engagement)->map(fn ($a) => $a['name']),
            'canEdit' => $this->can($request, $engagement, 'engagement.prepare_adjustments'),
            'canPost' => $this->can($request, $engagement, 'engagement.post_adjustments') && $adjustment->prepared_by !== $u->id,
            'threshold' => $threshold,
            'needsApproval' => $this->service->requiresApproval($adjustment, $threshold),
            'pendingApproval' => $pending,
            'canDecide' => $pending ? app(\App\Services\Approvals\ApprovalService::class)->canDecide($u, $pending) : false,
        ]);
    }

    public function edit(Request $request, Engagement $engagement, Adjustment $adjustment, LeadScheduleService $schedules): View
    {
        Gate::authorize('view', $engagement);
        abort_unless($this->can($request, $engagement, 'engagement.prepare_adjustments'), 403);

        return view('trial-balance.adjustment-form', [
            'engagement' => $engagement->load('client'),
            'adjustment' => $adjustment->load('lines'),
            'accounts' => $schedules->accounts($engagement)->filter(fn ($a) => $a['in_cy']),
        ]);
    }

    public function update(Request $request, Engagement $engagement, Adjustment $adjustment): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($this->can($request, $engagement, 'engagement.prepare_adjustments'), 403);
        $this->service->update($adjustment, $this->validated($request, false), $request->user());

        return redirect()->route('adjustments.show', [$engagement, $adjustment])->with('status', 'Adjustment updated.');
    }

    public function post(Request $request, Engagement $engagement, Adjustment $adjustment): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $this->service->post($adjustment, $request->user());

        return back()->with('status', "{$adjustment->number} posted. Lead schedules now reflect it.");
    }

    public function requestApproval(Request $request, Engagement $engagement, Adjustment $adjustment): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $this->service->requestApproval($adjustment, $request->user(), $data['comment'] ?? null);

        return back()->with('status', "{$adjustment->number} submitted to the engagement partner for approval.");
    }

    public function void(Request $request, Engagement $engagement, Adjustment $adjustment): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->service->void($adjustment, $data['reason'], $request->user());

        return back()->with('status', "{$adjustment->number} voided.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'type' => $creating ? ['required', Rule::enum(AdjustmentType::class)] : ['prohibited'],
            'description' => ['required', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:2', 'max:200'],
            'lines.*.account_code' => ['nullable', 'string', 'max:50'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.debit' => ['nullable', 'string', 'max:30'],
            'lines.*.credit' => ['nullable', 'string', 'max:30'],
        ]);
    }

    private function can(Request $request, Engagement $engagement, string $perm): bool
    {
        return ! $engagement->status->isLocked() && Permissions::allowsOnEngagement($request->user(), $engagement, $perm);
    }
}
