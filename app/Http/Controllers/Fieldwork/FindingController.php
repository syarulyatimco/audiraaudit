<?php

namespace App\Http\Controllers\Fieldwork;

use App\Enums\AdjustmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Planning\PlanningContext;
use App\Models\Engagement;
use App\Models\Finding;
use App\Models\SamplingPlan;
use App\Services\Findings\AuditDifferences;
use App\Services\Findings\FindingService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FindingController extends Controller
{
    use PlanningContext;

    public function index(Request $request, Engagement $engagement): View
    {
        Gate::authorize('view', $engagement);
        $type = $request->query('type');
        $findings = $engagement->findings()->with(['fsli', 'procedure'])
            ->when(in_array($type, \App\Enums\FindingType::values(), true), fn ($q) => $q->where('type', $type))
            ->get();

        return view('fieldwork.findings', [
            ...$this->aiData($engagement, 'findings', 'draft_findings'),
            'engagement' => $engagement->load('client'),
            'findings' => $findings,
            'type' => $type,
            'canManage' => $this->can($request, $engagement),
        ]);
    }

    public function create(Request $request, Engagement $engagement): View
    {
        abort_unless($this->can($request, $engagement), 403);
        $f = new Finding(['type' => $request->query('type', 'misstatement')]);
        if ($p = $engagement->procedures()->where('uuid', $request->query('procedure'))->first()) {
            $f->procedure_id = $p->id;
            $f->engagement_fsli_id = $p->engagement_fsli_id;
        }

        return view('fieldwork.finding-form', $this->formData($engagement, $f));
    }

    public function store(Request $request, Engagement $engagement, FindingService $service): RedirectResponse
    {
        abort_unless($this->can($request, $engagement), 403);
        $f = $service->create($engagement, $this->validated($request), $request->user());

        return redirect()->route('findings.show', [$engagement, $f])->with('status', "Finding {$f->reference} recorded.");
    }

    public function show(Request $request, Engagement $engagement, Finding $finding): View
    {
        Gate::authorize('view', $engagement);

        return view('fieldwork.finding', [
            ...$this->approvalData($request, $finding->approvals()->pending()->first()),
            'engagement' => $engagement->load('client'),
            'finding' => $finding->load(['fsli', 'procedure', 'samplingPlan', 'adjustment', 'creator', 'approvals.requester', 'approvals.decider']),
            'postedAdjustments' => $engagement->adjustments()->where('status', AdjustmentStatus::Posted->value)->orderBy('number')->get(),
            'canManage' => $this->can($request, $engagement),
        ]);
    }

    public function edit(Request $request, Engagement $engagement, Finding $finding): View
    {
        abort_unless($this->can($request, $engagement), 403);

        return view('fieldwork.finding-form', $this->formData($engagement, $finding));
    }

    public function update(Request $request, Engagement $engagement, Finding $finding, FindingService $service): RedirectResponse
    {
        abort_unless($this->can($request, $engagement), 403);
        $service->update($finding, $this->validated($request), $request->user());

        return redirect()->route('findings.show', [$engagement, $finding])->with('status', 'Finding updated.');
    }

    public function correction(Request $request, Engagement $engagement, Finding $finding, FindingService $service): RedirectResponse
    {
        abort_unless($this->can($request, $engagement), 403);
        $d = $request->validate(['correction_status' => ['required', Rule::in(\App\Enums\CorrectionStatus::values())], 'uncorrected_reason' => ['nullable', 'string', 'max:5000'], 'adjustment' => ['nullable', 'uuid']]);
        $service->setCorrection($finding, $d['correction_status'], $d['uncorrected_reason'] ?? null, $d['adjustment'] ?? null, $request->user());

        return back()->with('status', 'Correction status recorded.');
    }

    public function submit(Request $request, Engagement $engagement, Finding $finding, FindingService $service): RedirectResponse
    {
        abort_unless($this->can($request, $engagement), 403);
        $service->submitSignificance($finding, $request->user(), $request->validate(['comment' => ['nullable', 'string', 'max:2000']])['comment'] ?? null);

        return back()->with('status', 'Submitted to the engagement partner.');
    }

    public function resolve(Request $request, Engagement $engagement, Finding $finding, FindingService $service): RedirectResponse
    {
        abort_unless($this->can($request, $engagement), 403);
        $service->resolve($finding, $request->validate(['resolution' => ['required', 'string', 'max:5000']])['resolution'], $request->user());

        return back()->with('status', "Finding {$finding->reference} resolved.");
    }

    public function withdraw(Request $request, Engagement $engagement, Finding $finding, FindingService $service): RedirectResponse
    {
        abort_unless($this->can($request, $engagement), 403);
        $service->withdraw($finding, $request->validate(['resolution' => ['required', 'string', 'max:5000']])['resolution'], $request->user());

        return back()->with('status', "Finding {$finding->reference} withdrawn.");
    }

    public function fromSample(Request $request, Engagement $engagement, SamplingPlan $plan, FindingService $service): RedirectResponse
    {
        abort_unless($this->can($request, $engagement), 403);
        $created = $service->fromSample($plan, $request->user());

        return back()->with('status', count($created).' finding(s) recorded: '.collect($created)->pluck('reference')->join(', ').'.');
    }

    public function differences(Request $request, Engagement $engagement, AuditDifferences $sad): View
    {
        Gate::authorize('view', $engagement);

        return view('fieldwork.audit-differences', ['engagement' => $engagement->load('client'), 'sad' => $sad->build($engagement)]);
    }

    private function formData(Engagement $engagement, Finding $f): array
    {
        return [
            'engagement' => $engagement->load('client'), 'finding' => $f,
            'fslis' => $engagement->fslis()->get(),
            'procedures' => $engagement->procedures()->active()->orderBy('reference')->get(['id', 'reference', 'title']),
            'plans' => $engagement->samplingPlans()->orderBy('reference')->get(['id', 'reference', 'title']),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(\App\Enums\FindingType::values())],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
            'cause' => ['nullable', 'string', 'max:5000'],
            'recommendation' => ['nullable', 'string', 'max:5000'],
            'engagement_fsli_id' => ['nullable', 'integer'],
            'procedure_id' => ['nullable', 'integer'],
            'sampling_plan_id' => ['nullable', 'integer'],
            'misstatement_class' => ['nullable', Rule::in(\App\Enums\MisstatementClass::values())],
            'amount' => ['nullable', 'string', 'max:30'],
            'deficiency_severity' => ['nullable', Rule::in(\App\Enums\DeficiencySeverity::values())],
            'report_to_management' => ['nullable', 'boolean'],
            'management_response' => ['nullable', 'string', 'max:5000'],
            'is_significant' => ['nullable', 'boolean'],
        ]);
    }

    private function can(Request $request, Engagement $engagement): bool
    {
        return ! $engagement->status->isLocked() && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.manage_findings');
    }
}
