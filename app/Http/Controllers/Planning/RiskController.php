<?php

namespace App\Http\Controllers\Planning;

use App\Enums\Assertion;
use App\Enums\InherentRiskFactor;
use App\Enums\RiskLevel;
use App\Enums\RiskRating;
use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Models\Risk;
use App\Models\WorkingPaper;
use App\Services\Planning\RiskService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RiskController extends Controller
{
    use PlanningContext;

    public function index(Request $request, Engagement $engagement, RiskService $service): View
    {
        Gate::authorize('view', $engagement);

        return view('planning.risks', [
            ...$this->paperData($request, $engagement, WorkingPaper::RISK_ASSESSMENT, 'engagement.manage_risks'),
            ...$this->aiData($engagement, 'risk_assessment', 'suggest_risks'),
            'risks' => $engagement->risks()->with('fslis')->get(),
            'gaps' => $service->coverageGaps($engagement),
            'materiality' => $engagement->currentMateriality(),
        ]);
    }

    public function create(Request $request, Engagement $engagement): View
    {
        $this->authorizeManage($request, $engagement);

        return view('planning.risk-form', ['engagement' => $engagement->load('client'), 'risk' => new Risk(['level' => RiskLevel::Assertion, 'assertions' => [], 'inherent_factors' => []]), 'fslis' => $engagement->fslis()->get()]);
    }

    public function store(Request $request, Engagement $engagement, RiskService $service): RedirectResponse
    {
        $this->authorizeManage($request, $engagement);
        $risk = $service->create($engagement, $this->validated($request), $request->user());

        return redirect()->route('risks.show', [$engagement, $risk])->with('status', "Risk {$risk->reference} recorded.");
    }

    public function show(Request $request, Engagement $engagement, Risk $risk): View
    {
        Gate::authorize('view', $engagement);

        return view('planning.risk', [
            ...$this->approvalData($request, $risk->approvals()->pending()->first()),
            'engagement' => $engagement->load('client'),
            'risk' => $risk->load(['fslis', 'approvals.requester', 'approvals.decider']),
            'canManage' => ! $engagement->status->isLocked() && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.manage_risks'),
        ]);
    }

    public function edit(Request $request, Engagement $engagement, Risk $risk): View
    {
        $this->authorizeManage($request, $engagement);

        return view('planning.risk-form', ['engagement' => $engagement->load('client'), 'risk' => $risk->load('fslis'), 'fslis' => $engagement->fslis()->get()]);
    }

    public function update(Request $request, Engagement $engagement, Risk $risk, RiskService $service): RedirectResponse
    {
        $this->authorizeManage($request, $engagement);
        $service->update($risk, $this->validated($request), $request->user());

        return redirect()->route('risks.show', [$engagement, $risk])->with('status', 'Risk updated.');
    }

    public function submit(Request $request, Engagement $engagement, Risk $risk, RiskService $service): RedirectResponse
    {
        $this->authorizeManage($request, $engagement);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:5000']]);
        $service->submitSignificance($risk, $request->user(), $data['comment'] ?? null);

        return back()->with('status', 'Submitted to the engagement partner for approval.');
    }

    public function withdraw(Request $request, Engagement $engagement, Risk $risk, RiskService $service): RedirectResponse
    {
        $this->authorizeManage($request, $engagement);
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);
        $service->withdraw($risk, $request->user(), $data['reason']);

        return redirect()->route('risks.index', $engagement)->with('status', "Risk {$risk->reference} withdrawn.");
    }

    public function rebut(Request $request, Engagement $engagement, Risk $risk, RiskService $service): RedirectResponse
    {
        $this->authorizeManage($request, $engagement);
        $data = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:5000']]);
        $service->rebut($risk, $request->user(), $data['reason']);

        return redirect()->route('risks.index', $engagement)->with('status', 'Presumption rebutted and documented.');
    }

    public function presumed(Request $request, Engagement $engagement, RiskService $service): RedirectResponse
    {
        $this->authorizeManage($request, $engagement);
        $n = $service->addPresumedRisks($engagement, $request->user());

        return back()->with('status', $n ? "{$n} presumed fraud risk(s) added." : 'The presumed fraud risks are already in the register.');
    }

    private function authorizeManage(Request $request, Engagement $engagement): void
    {
        Gate::authorize('view', $engagement);
        abort_unless(Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.manage_risks'), 403);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
            'level' => ['required', Rule::enum(RiskLevel::class)],
            'fsli_ids' => ['nullable', 'array', 'max:100'],
            'fsli_ids.*' => ['integer'],
            'assertions' => ['nullable', 'array'],
            'assertions.*' => [Rule::enum(Assertion::class)],
            'inherent_factors' => ['nullable', 'array'],
            'inherent_factors.*' => [Rule::enum(InherentRiskFactor::class)],
            'likelihood' => ['required', Rule::enum(RiskRating::class)],
            'magnitude' => ['required', Rule::enum(RiskRating::class)],
            'is_significant' => ['nullable', 'boolean'],
            'is_fraud_risk' => ['nullable', 'boolean'],
            'controls_reliance' => ['nullable', 'boolean'],
            'controls_description' => ['nullable', 'string', 'max:5000'],
            'planned_response' => ['nullable', 'string', 'max:10000'],
        ]);
    }
}
