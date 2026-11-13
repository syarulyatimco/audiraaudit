<?php

namespace App\Http\Controllers\Fieldwork;

use App\Enums\Assertion;
use App\Enums\ProcedureNature;
use App\Enums\ProcedureStatus;
use App\Enums\ProcedureTiming;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Planning\PlanningContext;
use App\Models\Engagement;
use App\Models\Procedure;
use App\Models\WorkingPaper;
use App\Services\Programme\ProgrammeService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProgrammeController extends Controller
{
    use PlanningContext;

    public function index(Request $request, Engagement $engagement, ProgrammeService $service): View
    {
        Gate::authorize('view', $engagement);

        return view('fieldwork.programme', [
            ...$this->paperData($request, $engagement, WorkingPaper::AUDIT_PROGRAMME, 'engagement.manage_programme'),
            ...$this->aiData($engagement, 'audit_programme', 'draft_procedures'),
            'procedures' => $engagement->procedures()->with(['risks', 'fsli', 'assignee'])->get(),
            'uncovered' => $service->uncoveredRisks($engagement),
        ]);
    }

    public function create(Request $request, Engagement $engagement): View
    {
        $this->authorizeManage($request, $engagement);

        return view('fieldwork.procedure-form', $this->formData($engagement, new Procedure(['nature' => ProcedureNature::TestOfDetails, 'timing' => ProcedureTiming::Final, 'assertions' => []])));
    }

    public function store(Request $request, Engagement $engagement, ProgrammeService $service): RedirectResponse
    {
        $this->authorizeManage($request, $engagement);
        $p = $service->create($engagement, $this->validated($request), $request->user());

        return redirect()->route('programme.show', [$engagement, $p])->with('status', "Procedure {$p->reference} added.");
    }

    public function show(Request $request, Engagement $engagement, Procedure $procedure): View
    {
        Gate::authorize('view', $engagement);
        $locked = $engagement->status->isLocked();

        return view('fieldwork.procedure', [
            'engagement' => $engagement->load('client'),
            'procedure' => $procedure->load(['risks', 'fsli', 'assignee', 'samplingPlans', 'workingPaper', 'findings']),
            'canManage' => ! $locked && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.manage_programme'),
            'canExecute' => ! $locked && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.execute_procedures'),
            'canSample' => ! $locked && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.manage_sampling'),
            'canFindings' => ! $locked && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.manage_findings'),
        ]);
    }

    public function edit(Request $request, Engagement $engagement, Procedure $procedure): View
    {
        $this->authorizeManage($request, $engagement);

        return view('fieldwork.procedure-form', $this->formData($engagement, $procedure->load('risks')));
    }

    public function update(Request $request, Engagement $engagement, Procedure $procedure, ProgrammeService $service): RedirectResponse
    {
        $this->authorizeManage($request, $engagement);
        $service->update($procedure, $this->validated($request), $request->user());

        return redirect()->route('programme.show', [$engagement, $procedure])->with('status', 'Procedure updated.');
    }

    public function progress(Request $request, Engagement $engagement, Procedure $procedure, ProgrammeService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $data = $request->validate(['status' => ['required', Rule::enum(ProcedureStatus::class)], 'conclusion' => ['nullable', 'string', 'max:10000']]);
        $service->progress($procedure, $data['status'], $data['conclusion'] ?? null, $request->user());

        return back()->with('status', 'Progress saved.');
    }

    public function paper(Request $request, Engagement $engagement, Procedure $procedure, ProgrammeService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $wp = $service->workingPaper($procedure, $request->user());

        return redirect()->route('working-papers.show', [$engagement, $wp])->with('status', "Working paper {$wp->reference} is where the work and evidence for {$procedure->reference} are documented.");
    }

    public function withdraw(Request $request, Engagement $engagement, Procedure $procedure, ProgrammeService $service): RedirectResponse
    {
        $this->authorizeManage($request, $engagement);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $service->withdraw($procedure, $data['reason'], $request->user());

        return redirect()->route('programme.index', $engagement)->with('status', "Procedure {$procedure->reference} withdrawn.");
    }

    private function formData(Engagement $engagement, Procedure $procedure): array
    {
        return [
            'engagement' => $engagement->load('client'),
            'procedure' => $procedure,
            'risks' => $engagement->risks()->active()->get(),
            'fslis' => $engagement->fslis()->get(),
            'team' => $engagement->members()->with('user')->get(),
        ];
    }

    private function authorizeManage(Request $request, Engagement $engagement): void
    {
        Gate::authorize('view', $engagement);
        abort_unless(Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.manage_programme'), 403);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'steps' => ['required', 'string', 'max:20000'],
            'nature' => ['required', Rule::enum(ProcedureNature::class)],
            'timing' => ['required', Rule::enum(ProcedureTiming::class)],
            'assertions' => ['nullable', 'array'], 'assertions.*' => [Rule::enum(Assertion::class)],
            'risk_ids' => ['nullable', 'array'], 'risk_ids.*' => ['integer'],
            'engagement_fsli_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'],
            'budget_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ]);
    }
}
