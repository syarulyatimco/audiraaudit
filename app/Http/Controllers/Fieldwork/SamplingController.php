<?php

namespace App\Http\Controllers\Fieldwork;

use App\Enums\SampleTestType;
use App\Enums\SamplingApproach;
use App\Enums\SamplingMethod;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Planning\PlanningContext;
use App\Models\Engagement;
use App\Models\SampleItem;
use App\Models\SamplingPlan;
use App\Services\Sampling\SamplingService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SamplingController extends Controller
{
    use PlanningContext;

    public function index(Request $request, Engagement $engagement): View
    {
        Gate::authorize('view', $engagement);

        return view('fieldwork.sampling', [
            'engagement' => $engagement->load('client'),
            'plans' => $engagement->samplingPlans()->with(['procedure', 'fsli'])->get(),
            'procedures' => $engagement->procedures()->active()->get(),
            'fslis' => $engagement->fslis()->get(),
            'canManage' => ! $engagement->status->isLocked() && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.manage_sampling'),
        ]);
    }

    public function store(Request $request, Engagement $engagement, SamplingService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'], 'objective' => ['required', 'string', 'max:5000'],
            'test_type' => ['required', Rule::enum(SampleTestType::class)], 'method' => ['required', Rule::enum(SamplingMethod::class)],
            'approach' => ['nullable', Rule::enum(SamplingApproach::class)],
            'procedure_id' => ['nullable', 'integer'], 'engagement_fsli_id' => ['nullable', 'integer'],
        ]);
        $plan = $service->create($engagement, $data, $request->user());

        return redirect()->route('sampling.show', [$engagement, $plan])->with('status', "Sampling plan {$plan->reference} created. Load the population next.");
    }

    public function show(Request $request, Engagement $engagement, SamplingPlan $plan, SamplingService $service): View
    {
        Gate::authorize('view', $engagement);
        $plan->load(['procedure', 'fsli', 'approvals.requester', 'approvals.decider']);
        $locked = $engagement->status->isLocked();

        return view('fieldwork.sampling-plan', [
            ...$this->approvalData($request, $plan->approvals->firstWhere('status', \App\Enums\ApprovalStatus::Pending)),
            ...$this->aiData($engagement, 'sampling', 'recommend_parameters'),
            'aiRun' => $engagement->aiRuns()->where('agent', 'sampling')->where('input->subject', $plan->uuid)->first(),
            'testRun' => $engagement->aiRuns()->where('agent', 'testing_evidence')->where('input->subject', $plan->uuid)->first(),
            'testUnavailable' => app(\App\Ai\Runtime\AiOrchestrator::class)->unavailableReasons($engagement, 'testing_evidence'),
            'engagement' => $engagement->load('client'),
            'plan' => $plan,
            'items' => $plan->sampleItems()->with(['populationItem', 'tester', 'evidence'])->get(),
            'documents' => $engagement->documents()->orderBy('title')->get(['id', 'uuid', 'title']),
            'sampleFindings' => \App\Models\Finding::where('sampling_plan_id', $plan->id)->active()->orderBy('reference')->get(),
            'canFindings' => ! $locked && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.manage_findings'),
            'population' => $plan->populationItems()->orderByRaw('abs(amount_sen) desc')->limit($plan->method->isManual() ? 500 : 15)->get(),
            'materiality' => $engagement->currentMateriality(),
            'canManage' => ! $locked && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.manage_sampling'),
            'canExecute' => ! $locked && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.execute_procedures'),
            'reproducible' => $plan->selection_seed ? $service->verifySelection($plan) : null,
            'preview' => $plan->status->value === 'selected' ? $service->computeEvaluation($plan) : null,
        ]);
    }

    public function population(Request $request, Engagement $engagement, SamplingPlan $plan, SamplingService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $request->validate(['file' => ['required', 'file', 'max:20480', 'extensions:csv,xlsx,txt']]);
        $service->uploadPopulation($plan, $request->file('file'), $request->user());

        return back()->with('status', 'Population loaded and hashed.');
    }

    public function parameters(Request $request, Engagement $engagement, SamplingPlan $plan, SamplingService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate([
            'control_total' => ['nullable', 'string', 'max:30'], 'control_total_source' => ['nullable', 'string', 'max:200'], 'reconciliation_note' => ['nullable', 'string', 'max:5000'],
            'tolerable_misstatement' => ['nullable', 'string', 'max:30'], 'expected_misstatement' => ['nullable', 'string', 'max:30'], 'key_item_threshold' => ['nullable', 'string', 'max:30'],
            'confidence_level' => ['nullable', 'numeric', 'gt:0', 'lt:100'], 'expansion_factor' => ['nullable', 'numeric', 'gt:0', 'max:10'], 'assurance_factor' => ['nullable', 'numeric', 'gt:0', 'max:10'],
            'tolerable_deviation_rate' => ['nullable', 'numeric', 'gt:0', 'lt:100'], 'expected_deviation_rate' => ['nullable', 'numeric', 'min:0', 'lt:100'],
            'strata' => ['nullable', 'array', 'max:10'], 'strata.*' => ['nullable', 'string', 'max:30'],
            'method_rationale' => ['nullable', 'string', 'max:10000'],
        ]);
        $plan = $service->saveParameters($plan, $data, $request->user());

        return back()->with('status', $plan->sample_size ? "Parameters saved. Sample size: {$plan->sample_size} (plus key items)." : 'Parameters saved.');
    }

    public function submit(Request $request, Engagement $engagement, SamplingPlan $plan, SamplingService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:5000']]);
        $service->submit($plan, $request->user(), $data['comment'] ?? null);

        return back()->with('status', 'Submitted for approval. Selection is possible once the plan is approved.');
    }

    public function select(Request $request, Engagement $engagement, SamplingPlan $plan, SamplingService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['items' => ['nullable', 'array', 'max:1000'], 'items.*' => ['nullable', 'string', 'max:1000']]);
        $manual = array_filter($data['items'] ?? [], 'filled');
        $n = $service->select($plan, $request->user(), $manual);

        return back()->with('status', "{$n} item(s) selected.");
    }

    public function result(Request $request, Engagement $engagement, SamplingPlan $plan, SampleItem $item, SamplingService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($item->sampling_plan_id === $plan->id, 404);
        $data = $request->validate(['result' => ['required', Rule::in(['no_exception', 'exception'])], 'audited_amount' => ['nullable', 'string', 'max:30'], 'result_note' => ['nullable', 'string', 'max:5000'], 'evidence_document' => ['nullable', 'uuid']]);
        $service->recordResult($item, $data, $request->user());

        return back()->with('status', 'Result recorded.');
    }

    public function evaluate(Request $request, Engagement $engagement, SamplingPlan $plan, SamplingService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['conclusion' => ['required', 'string', 'max:10000']]);
        $service->evaluate($plan, $data['conclusion'], $request->user());

        return back()->with('status', 'Sample evaluated.');
    }
}
