<?php

namespace App\Http\Controllers\Completion;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Planning\PlanningContext;
use App\Models\CompletionItem;
use App\Models\Engagement;
use App\Services\Completion\CompletionService;
use App\Services\Findings\AuditDifferences;
use App\Support\Authorization\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CompletionController extends Controller
{
    use PlanningContext;

    public function index(Request $request, Engagement $engagement, CompletionService $service, AuditDifferences $sad): View
    {
        Gate::authorize('view', $engagement);
        $locked = $engagement->status->isLocked();
        $c = $locked ? ($engagement->completion ?? new \App\Models\EngagementCompletion) : $service->record($engagement);
        $c->load(['approvals.requester', 'approvals.decider', 'eqrReviewer']);
        $pending = $c->approvals->firstWhere('status', \App\Enums\ApprovalStatus::Pending);
        $u = $request->user();

        return view('completion.index', [
            ...$this->approvalData($request, $pending),
            ...$this->aiData($engagement, 'completion', 'draft_checklist'),
            'qrRun' => $engagement->aiRuns()->where('agent', 'quality_review')->where('task', 'pre_review')->first(),
            'qrUnavailable' => app(\App\Ai\Runtime\AiOrchestrator::class)->unavailableReasons($engagement, 'quality_review'),
            'engagement' => $engagement->load('client'),
            'completion' => $c,
            'items' => $engagement->completionItems()->with('completer')->get(),
            'conclusionsGates' => $service->conclusionsGates($engagement),
            'completionGates' => $c->exists ? $service->completionGates($engagement) : [],
            'eqrRequired' => $service->eqrRequired($engagement),
            'sad' => $sad->build($engagement),
            'canManage' => ! $locked && Permissions::allowsOnEngagement($u, $engagement, 'engagement.manage_completion'),
            'canEqr' => ! $locked && Permissions::allowsOnEngagement($u, $engagement, 'engagement.eqr_review'),
        ]);
    }

    public function item(Request $request, Engagement $engagement, CompletionItem $completionItem, CompletionService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $d = $request->validate(['status' => ['required', Rule::in(\App\Enums\CompletionItemStatus::values())], 'response' => ['nullable', 'string', 'max:10000']]);
        $service->updateItem($completionItem, $d['status'], $d['response'] ?? null, $request->user());

        return back()->with('status', 'Checklist updated.');
    }

    public function conclusions(Request $request, Engagement $engagement, CompletionService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $d = $request->validate(['misstatements_conclusion' => ['nullable', 'string', 'max:10000'], 'going_concern_conclusion' => ['nullable', 'string', 'max:10000'], 'overall_conclusion' => ['nullable', 'string', 'max:10000']]);
        $service->saveConclusions($service->record($engagement), $d, $request->user());

        return back()->with('status', 'Conclusions saved.');
    }

    public function submit(Request $request, Engagement $engagement, string $stage, CompletionService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $comment = $request->validate(['comment' => ['nullable', 'string', 'max:2000']])['comment'] ?? null;
        $c = $service->record($engagement);
        match ($stage) {
            'conclusions' => $service->requestConclusions($c, $request->user(), $comment),
            'completion' => $service->requestCompletion($c, $request->user(), $comment),
            default => abort(404),
        };

        return back()->with('status', 'Submitted to the engagement partner.');
    }

    public function eqr(Request $request, Engagement $engagement, CompletionService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $service->recordEqr($service->record($engagement), (string) $request->validate(['eqr_conclusion' => ['required', 'string', 'max:10000']])['eqr_conclusion'], $request->user());

        return back()->with('status', 'Engagement quality review recorded.');
    }
}
