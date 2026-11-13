<?php

namespace App\Http\Controllers\Planning;

use App\Ai\Runtime\AiOrchestrator;
use App\Models\Approval;
use App\Models\Engagement;
use App\Models\WorkingPaper;
use App\Services\Approvals\ApprovalService;
use App\Services\Planning\PlanningPapers;
use App\Support\Authorization\Permissions;
use Illuminate\Http\Request;

/** Shared view data for the planning pages: the planning paper with sign-off panels and AI state. */
trait PlanningContext
{
    protected function paperData(Request $request, Engagement $engagement, string $key, string $editPermission): array
    {
        $papers = app(PlanningPapers::class);
        $perms = \App\Http\Controllers\WorkingPapers\WorkingPaperController::permissionsFor($request, $engagement);
        $canEdit = ! $engagement->status->isLocked() && Permissions::allowsOnEngagement($request->user(), $engagement, $editPermission);

        $wp = $papers->find($engagement, $key);
        if (! $wp && $canEdit) {
            $wp = $papers->paper($engagement, $key, $request->user());
        }
        $wp?->load(['signoffs.user', 'reviewNotes.raiser', 'reviewNotes.responder', 'reviewNotes.clearer', 'documents.currentVersion', 'citations.paragraph.version.standard']);

        return [
            'engagement' => $engagement->load(['client', 'members']),
            'wp' => $wp,
            'gates' => $wp ? $papers->gates($wp) : [],
            'linkable' => $wp ? $engagement->documents()->active()->whereNotIn('id', $wp->documents->pluck('id'))->orderBy('title')->get() : collect(),
            'canEditPlanning' => $canEdit,
            ...$perms,
        ];
    }

    protected function aiData(Engagement $engagement, string $agent, string $task): array
    {
        return [
            'aiRun' => $engagement->aiRuns()->where('agent', $agent)->where('task', $task)->first(),
            'aiUnavailable' => app(AiOrchestrator::class)->unavailableReasons($engagement, $agent),
        ];
    }

    protected function approvalData(Request $request, ?Approval $pending): array
    {
        return [
            'pendingApproval' => $pending,
            'canDecide' => $pending ? app(ApprovalService::class)->canDecide($request->user(), $pending) : false,
        ];
    }
}
