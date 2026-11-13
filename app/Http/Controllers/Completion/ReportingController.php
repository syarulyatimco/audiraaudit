<?php

namespace App\Http\Controllers\Completion;

use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Services\Reporting\FinancialStatements;
use App\Services\Reporting\ReportService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportingController extends Controller
{
    public function statements(Request $request, Engagement $engagement, FinancialStatements $fs): View
    {
        Gate::authorize('view', $engagement);
        $report = $engagement->auditReport;

        return view('completion.statements', [
            'engagement' => $engagement->load('client'),
            // A signed engagement shows the figures as they were when the report was signed.
            'fs' => $report?->isSigned() ? $report->fs_snapshot : $fs->build($engagement),
            'snapshot' => (bool) $report?->isSigned(),
            'print' => $request->boolean('print'),
        ]);
    }

    public function report(Request $request, Engagement $engagement, ReportService $service): View
    {
        Gate::authorize('view', $engagement);
        $locked = $engagement->status->isLocked();
        $report = $locked ? ($engagement->auditReport ?? new \App\Models\AuditReport) : $service->report($engagement);
        $report->load(['approvals.requester', 'approvals.decider', 'signer']);
        $pending = $report->approvals->firstWhere('status', \App\Enums\ApprovalStatus::Pending);

        return view('completion.report', [
            'engagement' => $engagement->load(['client', 'completion']),
            'report' => $report,
            'pendingApproval' => $pending,
            'canDecide' => $pending ? app(\App\Services\Approvals\ApprovalService::class)->canDecide($request->user(), $pending) : false,
            'opinionGates' => $report->exists ? $service->opinionGates($report) : [],
            'signoffGates' => $report->exists ? $service->signoffGates($report) : [],
            'hasTemplate' => $report->opinion ? \App\Models\ReportTemplate::where('opinion', $report->opinion->value)->exists() : false,
            'canPrepare' => ! $locked && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.prepare_report'),
            'print' => $request->boolean('print'),
        ]);
    }

    public function save(Request $request, Engagement $engagement, ReportService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $d = $request->validate([
            'opinion' => ['nullable', Rule::in(\App\Enums\AuditOpinion::values())],
            'basis_for_modification' => ['nullable', 'string', 'max:10000'],
            'going_concern_uncertainty' => ['nullable', 'boolean'],
            'going_concern_paragraph' => ['nullable', 'string', 'max:10000'],
            'emphasis_of_matter' => ['nullable', 'string', 'max:10000'],
            'other_matter' => ['nullable', 'string', 'max:10000'],
            'report_date' => ['nullable', 'date'],
            'body' => ['nullable', 'string', 'max:100000'],
        ]);
        $r = $service->report($engagement);
        if ($request->input('action') === 'generate') {
            $service->save($r, collect($d)->except('body')->all(), $request->user());
            $service->generate($r->fresh(), $request->user());

            return back()->with('status', 'Report text generated from the template. Review every paragraph.');
        }
        $service->save($r, $d, $request->user());

        return back()->with('status', 'Report saved.');
    }

    public function submit(Request $request, Engagement $engagement, string $stage, ReportService $service): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        $comment = $request->validate(['comment' => ['nullable', 'string', 'max:2000']])['comment'] ?? null;
        $r = $service->report($engagement);
        match ($stage) {
            'opinion' => $service->requestOpinion($r, $request->user(), $comment),
            'signoff' => $service->requestSignoff($r, $request->user(), $comment),
            default => abort(404),
        };

        return back()->with('status', 'Submitted to the engagement partner.');
    }
}
