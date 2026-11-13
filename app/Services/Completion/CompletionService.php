<?php

namespace App\Services\Completion;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\CompletionItemStatus;
use App\Enums\CorrectionStatus;
use App\Enums\EngagementRole;
use App\Enums\FindingStatus;
use App\Enums\ProcedureStatus;
use App\Enums\ReviewNoteStatus;
use App\Enums\SignificanceStatus;
use App\Enums\WorkingPaperStatus;
use App\Models\AiRun;
use App\Models\Approval;
use App\Models\CompletionItem;
use App\Models\Engagement;
use App\Models\EngagementCompletion;
use App\Models\Finding;
use App\Models\ReviewNote;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\TrialBalance\LeadScheduleService;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\EngagementGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Completion (ISA 220, 450, 560, 570, 580; ISQM 2): checklist → final conclusions (approved by the
 * engagement partner) → engagement quality review (when an EQR reviewer is assigned) → completion
 * approval. Each step has explicit gates; nothing is approved automatically.
 */
class CompletionService
{
    /** Approval types that belong to completion and reporting themselves (excluded from "pending elsewhere"). */
    private const OWN_TYPES = ['final_conclusions', 'completion', 'audit_opinion', 'report_signoff'];

    public function __construct(private ApprovalService $approvals, private ActivityLogger $logger, private LeadScheduleService $schedules) {}

    /** The completion record and its checklist, created from the firm template on first use. */
    public function record(Engagement $engagement): EngagementCompletion
    {
        $c = $engagement->completion ?? EngagementCompletion::firstOrCreate(['engagement_id' => $engagement->id]);
        $existing = $engagement->completionItems()->pluck('key')->all();
        foreach (config('audira.completion.items') as $key => [$section, $title]) {
            if (! in_array($key, $existing, true)) {
                CompletionItem::create(['engagement_id' => $engagement->id, 'key' => $key, 'section' => $section, 'title' => $title]);
            }
        }

        return $c;
    }

    public function updateItem(CompletionItem $item, string $status, ?string $response, User $actor, ?AiRun $aiRun = null): void
    {
        $e = $item->engagement;
        EngagementGuard::assertEditable($e);
        $this->authorise($actor, $e, 'engagement.manage_completion');
        $this->assertConclusionsOpen($e);
        $st = CompletionItemStatus::tryFrom($status) ?? throw ValidationException::withMessages(['status' => 'Choose a status.']);
        if ($st !== CompletionItemStatus::Open && blank($response)) {
            throw ValidationException::withMessages(['response' => $st === CompletionItemStatus::NotApplicable ? 'Explain why this is not applicable.' : 'Record the work done and the conclusion.']);
        }
        $item->forceFill([
            'status' => $st, 'response' => filled($response) ? trim($response) : null, 'updated_by' => $actor->id,
            'completed_by' => $st === CompletionItemStatus::Open ? null : $actor->id, 'completed_at' => $st === CompletionItemStatus::Open ? null : now(),
            'source' => $aiRun ? 'ai' : $item->source, 'ai_run_id' => $aiRun?->id ?? $item->ai_run_id,
        ])->save();
        $this->logger->log('completion.item', "Completion item \"{$item->title}\" marked {$st->label()}", $item, [], $actor);
    }

    /** Save an AI-drafted response on a still-open item; a person marks it done. */
    public function draftResponse(CompletionItem $item, string $response, User $actor, AiRun $aiRun): void
    {
        EngagementGuard::assertEditable($item->engagement);
        $this->authorise($actor, $item->engagement, 'engagement.manage_completion');
        $this->assertConclusionsOpen($item->engagement);
        if ($item->status !== CompletionItemStatus::Open) {
            return;
        }
        $item->forceFill(['response' => trim($response), 'source' => 'ai', 'ai_run_id' => $aiRun->id, 'updated_by' => $actor->id])->save();
    }

    public function saveConclusions(EngagementCompletion $c, array $data, User $actor): void
    {
        $e = $c->engagement;
        EngagementGuard::assertEditable($e);
        $this->authorise($actor, $e, 'engagement.manage_completion');
        if ($c->conclusions_status === SignificanceStatus::Pending) {
            throw ValidationException::withMessages(['conclusions' => 'The conclusions are awaiting partner approval.']);
        }
        if ($c->completion_status === SignificanceStatus::Approved || $c->completion_status === SignificanceStatus::Pending) {
            throw ValidationException::withMessages(['conclusions' => 'Completion is approved or awaiting approval; the conclusions are locked.']);
        }
        $before = $c->conclusionsHash();
        $c->fill([
            'misstatements_conclusion' => $this->clean($data['misstatements_conclusion'] ?? null),
            'going_concern_conclusion' => $this->clean($data['going_concern_conclusion'] ?? null),
            'overall_conclusion' => $this->clean($data['overall_conclusion'] ?? null),
            'updated_by' => $actor->id,
        ]);
        if ($c->conclusionsHash() !== $before && $c->conclusions_status !== null) {
            // Changed after the partner's decision: a fresh approval is needed, and the EQR must look again.
            $c->conclusions_status = null;
            $c->eqr_conclusion = null;
            $c->eqr_completed_by = null;
            $c->eqr_completed_at = null;
        }
        $c->save();
        $this->logger->log('completion.conclusions', 'Final conclusions updated', $c, [], $actor);
    }

    /** @return array<string, array{ok: bool, detail: string}> */
    public function conclusionsGates(Engagement $e): array
    {
        $c = $e->completion;
        $open = $e->completionItems()->where('status', CompletionItemStatus::Open->value)->count();
        $findings = Finding::where('engagement_id', $e->id)->active()->get();
        $awaiting = $findings->filter(fn ($f) => $f->isMisstatement() && $f->correction_status === CorrectionStatus::Proposed)->count();
        $sig = $findings->filter->needsApproval()->count();
        $pending = Approval::where('engagement_id', $e->id)->pending()->whereNotIn('type', self::OWN_TYPES)->count();

        return [
            'checklist' => ['ok' => $open === 0, 'detail' => $open ? "{$open} checklist item(s) still open" : 'Every checklist item answered'],
            'conclusions' => ['ok' => $c && filled($c->misstatements_conclusion) && filled($c->going_concern_conclusion) && filled($c->overall_conclusion), 'detail' => 'Conclusions on misstatements, going concern and overall recorded'],
            'management_responses' => ['ok' => $awaiting === 0, 'detail' => $awaiting ? "{$awaiting} misstatement(s) awaiting management's response" : 'Management has responded to every misstatement'],
            'significant_findings' => ['ok' => $sig === 0, 'detail' => $sig ? "{$sig} significant finding(s) not yet approved" : 'Significant findings approved'],
            'pending_approvals' => ['ok' => $pending === 0, 'detail' => $pending ? "{$pending} other approval request(s) still pending" : 'No other approvals pending'],
        ];
    }

    /** @return array<string, array{ok: bool, detail: string}> */
    public function completionGates(Engagement $e): array
    {
        $c = $e->completion;
        $papers = $e->workingPapers()->get();
        $unreviewed = $papers->where('status', '!=', WorkingPaperStatus::Reviewed)->count();
        $notes = ReviewNote::whereIn('working_paper_id', $papers->pluck('id'))->where('status', '!=', ReviewNoteStatus::Cleared->value)->count();
        $procs = $e->procedures()->active()->where('status', '!=', ProcedureStatus::Completed->value)->count();
        $plans = $e->samplingPlans()->whereIn('status', ['pending_approval', 'approved', 'selected'])->count();
        $checks = $this->schedules->build($e)['checks'];
        $tbOk = $checks['tb_balances'] && $checks['sofp_difference'] === 0 && $checks['unmapped_count'] === 0;
        $eqrNeeded = $this->eqrRequired($e);

        return [
            'conclusions_approved' => ['ok' => $c?->conclusions_status === SignificanceStatus::Approved, 'detail' => 'Final conclusions approved by the engagement partner'],
            'working_papers' => ['ok' => $papers->isNotEmpty() && $unreviewed === 0, 'detail' => $unreviewed ? "{$unreviewed} working paper(s) not yet reviewed" : 'All working papers prepared and reviewed'],
            'review_notes' => ['ok' => $notes === 0, 'detail' => $notes ? "{$notes} review note(s) not cleared" : 'All review notes cleared'],
            'procedures' => ['ok' => $procs === 0, 'detail' => $procs ? "{$procs} procedure(s) not completed" : 'All procedures completed'],
            'sampling' => ['ok' => $plans === 0, 'detail' => $plans ? "{$plans} sample(s) not yet evaluated" : 'All samples evaluated'],
            'trial_balance' => ['ok' => $tbOk, 'detail' => $tbOk ? 'Adjusted TB balances and is fully mapped' : 'Adjusted TB does not balance, or accounts are unmapped'],
            'eqr' => ['ok' => ! $eqrNeeded || $c?->eqr_completed_at !== null, 'detail' => $eqrNeeded ? ($c?->eqr_completed_at ? 'Engagement quality review completed' : 'Engagement quality review not completed') : 'No engagement quality reviewer assigned'],
        ];
    }

    public function requestConclusions(EngagementCompletion $c, User $actor, ?string $comment): Approval
    {
        return $this->request($c, ApprovalType::FinalConclusions, 'conclusions_status', $this->conclusionsGates($c->engagement), $actor, $comment, [
            'misstatements' => $c->misstatements_conclusion, 'going_concern' => $c->going_concern_conclusion, 'overall' => $c->overall_conclusion, 'sha256' => $c->conclusionsHash(),
        ]);
    }

    public function requestCompletion(EngagementCompletion $c, User $actor, ?string $comment): Approval
    {
        return $this->request($c, ApprovalType::Completion, 'completion_status', $this->completionGates($c->engagement), $actor, $comment, [
            'gates' => $this->completionGates($c->engagement), 'conclusions_sha256' => $c->conclusionsHash(),
        ]);
    }

    public function recordEqr(EngagementCompletion $c, string $conclusion, User $actor): void
    {
        $e = $c->engagement;
        EngagementGuard::assertEditable($e);
        $this->authorise($actor, $e, 'engagement.eqr_review');
        if ($c->conclusions_status !== SignificanceStatus::Approved) {
            throw ValidationException::withMessages(['eqr_conclusion' => 'The engagement quality review is completed after the final conclusions are approved.']);
        }
        if (in_array($c->completion_status, [SignificanceStatus::Pending, SignificanceStatus::Approved], true)) {
            throw ValidationException::withMessages(['eqr_conclusion' => 'Completion is approved or awaiting approval.']);
        }
        if (blank($conclusion)) {
            throw ValidationException::withMessages(['eqr_conclusion' => 'Record the conclusion of the engagement quality review.']);
        }
        $c->forceFill(['eqr_conclusion' => trim($conclusion), 'eqr_completed_by' => $actor->id, 'eqr_completed_at' => now()])->save();
        $this->logger->log('completion.eqr', 'Engagement quality review completed', $c, [], $actor);
    }

    public function applyDecision(EngagementCompletion $c, Approval $approval): void
    {
        $col = $approval->type === ApprovalType::FinalConclusions ? 'conclusions_status' : 'completion_status';
        $status = match ($approval->status) {
            ApprovalStatus::Approved => SignificanceStatus::Approved,
            ApprovalStatus::Rejected => SignificanceStatus::Rejected,
            default => null,
        };
        if ($status === SignificanceStatus::Approved && $col === 'conclusions_status' && ($approval->snapshot['sha256'] ?? null) !== $c->conclusionsHash()) {
            throw ValidationException::withMessages(['approval' => 'The conclusions changed after this request was made.']);
        }
        $c->forceFill([$col => $status])->save();
    }

    public function eqrRequired(Engagement $e): bool
    {
        return $e->members()->where('role', EngagementRole::EqrReviewer->value)->exists();
    }

    private function request(EngagementCompletion $c, ApprovalType $type, string $col, array $gates, User $actor, ?string $comment, array $snapshot): Approval
    {
        $e = $c->engagement;
        EngagementGuard::assertEditable($e);
        $this->authorise($actor, $e, 'engagement.manage_completion');
        if (in_array($c->{$col}, [SignificanceStatus::Pending, SignificanceStatus::Approved], true)) {
            throw ValidationException::withMessages(['approval' => 'Already approved or awaiting approval.']);
        }
        $failed = collect($gates)->reject(fn ($g) => $g['ok'])->pluck('detail')->all();
        if ($failed) {
            throw ValidationException::withMessages(['approval' => 'Not ready: '.implode('; ', $failed).'.']);
        }

        return DB::transaction(function () use ($c, $type, $col, $actor, $comment, $snapshot) {
            $approval = $this->approvals->request($c, $type, $actor, $comment, $snapshot);
            $c->forceFill([$col => SignificanceStatus::Pending])->save();

            return $approval;
        });
    }

    private function assertConclusionsOpen(Engagement $e): void
    {
        $s = $e->completion?->conclusions_status;
        if ($s === SignificanceStatus::Pending || $s === SignificanceStatus::Approved) {
            throw ValidationException::withMessages(['status' => 'The checklist is locked once the final conclusions are submitted or approved.']);
        }
    }

    private function clean(?string $v): ?string
    {
        return filled($v) ? trim($v) : null;
    }

    private function authorise(User $actor, Engagement $e, string $perm): void
    {
        if (! Permissions::allowsOnEngagement($actor, $e, $perm)) {
            throw new AuthorizationException('Your role on this engagement does not allow this action.');
        }
    }
}
