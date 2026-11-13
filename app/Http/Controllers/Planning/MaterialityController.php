<?php

namespace App\Http\Controllers\Planning;

use App\Enums\BenchmarkBasis;
use App\Enums\MaterialityBenchmark;
use App\Enums\MaterialityStage;
use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Models\MaterialityAssessment;
use App\Models\WorkingPaper;
use App\Services\Planning\MaterialityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MaterialityController extends Controller
{
    use PlanningContext;

    public function index(Request $request, Engagement $engagement, MaterialityService $service): View
    {
        Gate::authorize('view', $engagement);
        $open = $service->openVersion($engagement)?->load('specifics');
        $current = $engagement->currentMateriality()?->load('specifics.fsli');
        $benchmarks = $service->benchmarks($engagement);

        return view('planning.materiality', [
            ...$this->paperData($request, $engagement, WorkingPaper::MATERIALITY, 'engagement.prepare_materiality'),
            ...$this->aiData($engagement, 'materiality', 'recommend_materiality'),
            ...$this->approvalData($request, $open?->approvals()->pending()->first()),
            'current' => $current,
            'open' => $open,
            'history' => $engagement->materialityAssessments()->with(['creator', 'approvals.decider'])->get(),
            'benchmarks' => $benchmarks,
            'fslis' => $engagement->fslis()->get(),
            // The benchmark figure behind the approved version has since changed in the TB.
            'drift' => $current && $current->benchmark_basis === BenchmarkBasis::CurrentYear
                && ($benchmarks['current'][$current->benchmark->value] ?? null) !== null
                && abs($benchmarks['current'][$current->benchmark->value]) !== $current->benchmark_amount_sen,
        ]);
    }

    public function save(Request $request, Engagement $engagement, MaterialityService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $service->saveDraft($engagement, $this->validated($request), $request->user());

        return back()->with('status', 'Materiality draft saved. Amounts are calculated by AUDIRA from the benchmark and your percentages.');
    }

    public function submit(Request $request, Engagement $engagement, MaterialityAssessment $materiality, MaterialityService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:5000']]);
        $service->submit($materiality, $request->user(), $data['comment'] ?? null);

        return back()->with('status', 'Submitted to the engagement partner for approval.');
    }

    public function revise(Request $request, Engagement $engagement, MaterialityService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate([
            'stage' => ['required', Rule::in([MaterialityStage::Revised->value, MaterialityStage::Final->value])],
            'revision_reason' => ['required', 'string', 'max:5000'],
        ]);
        $service->startRevision($engagement, $request->user(), $data['stage'], $data['revision_reason']);

        return back()->with('status', 'Revision started from the approved version. The approved version stays in force until the revision is approved.');
    }

    public function discard(Request $request, Engagement $engagement, MaterialityAssessment $materiality, MaterialityService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $service->discard($materiality, $request->user());

        return back()->with('status', 'Draft discarded.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'stage' => ['nullable', Rule::enum(MaterialityStage::class)],
            'benchmark' => ['required', Rule::enum(MaterialityBenchmark::class)],
            'benchmark_basis' => ['required', Rule::enum(BenchmarkBasis::class)],
            'benchmark_amount' => ['nullable', 'string', 'max:30'],
            'benchmark_reason' => ['nullable', 'string', 'max:5000'],
            'om_percentage' => ['required', 'numeric', 'gt:0', 'max:100'],
            'pm_percentage' => ['required', 'numeric', 'gt:0', 'max:100'],
            'ct_percentage' => ['required', 'numeric', 'gt:0', 'lt:100'],
            'rationale' => ['required', 'string', 'max:10000'],
            'pm_rationale' => ['nullable', 'string', 'max:5000'],
            'ct_rationale' => ['nullable', 'string', 'max:5000'],
            'revision_reason' => ['nullable', 'string', 'max:5000'],
            'specifics' => ['nullable', 'array', 'max:50'],
            'specifics.*.area' => ['required', 'string', 'max:200'],
            'specifics.*.engagement_fsli_id' => ['nullable', 'integer'],
            'specifics.*.amount' => ['required', 'string', 'max:30'],
            'specifics.*.rationale' => ['required', 'string', 'max:2000'],
        ]);
    }
}
