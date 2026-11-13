<?php

namespace App\Services\Approvals;

use App\Contracts\Approvable;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Models\Approval;
use App\Models\User;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only path by which a human-approval gate (CLAUDE.md §9) can be passed.
 * - decided_by must be a human firm user with the configured decider role
 * - the requester can never decide their own request (also a DB CHECK constraint)
 * - decisions are never overwritten; resubmission creates a new approval row
 */
class ApprovalService
{
    public function __construct(private ActivityLogger $logger) {}

    /**
     * @param  Model&Approvable  $approvable
     * @param  array<string, mixed>  $snapshot  state of the subject at request time
     */
    public function request(Model $approvable, ApprovalType $type, User $requester, ?string $comment = null, array $snapshot = []): Approval
    {
        $exists = Approval::where('approvable_type', $approvable->getMorphClass())
            ->where('approvable_id', $approvable->getKey())
            ->where('type', $type->value)
            ->pending()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['approval' => 'An approval request is already pending.']);
        }

        $engagement = $approvable->approvalEngagement();
        if ($engagement && $this->eligibleDeciders($engagement, $type, $requester)->isEmpty()) {
            throw ValidationException::withMessages(['approval' => 'No one else on this engagement can approve this request. '
                .'It must be submitted by a team member other than the approver (for example the manager or senior), so the engagement partner can decide it.']);
        }

        $approval = new Approval([
            'type' => $type,
            'request_comment' => $comment,
            'snapshot' => $snapshot ?: null,
            'requested_by' => $requester->id,
            'requested_at' => now(),
        ]);
        $approval->firm_id = $approvable->getAttribute('firm_id');
        $approval->engagement_id = $engagement?->id;
        $approval->approvable()->associate($approvable);
        $approval->save();

        $this->logger->log('approval.requested', "{$type->label()} approval requested for {$approvable->approvalTitle()}", $approvable, [
            'approval' => $approval->uuid, 'type' => $type->value,
        ], $requester);

        return $approval;
    }

    public function canDecide(User $user, Approval $approval): bool
    {
        return $approval->isPending()
            && $user->firm_id === $approval->firm_id
            && $user->id !== $approval->requested_by
            && Permissions::canDecide($user, $approval->type)
            && $this->holdsEngagementRole($user, $approval);
    }

    /** Engagement-scoped approvals also require the decider's role on that engagement. */
    private function holdsEngagementRole(User $user, Approval $approval): bool
    {
        if (! $approval->engagement_id) {
            return true;
        }
        $roles = config('audira.approval_engagement_roles.'.$approval->type->value, []);
        $role = \App\Models\EngagementMember::where('engagement_id', $approval->engagement_id)->where('user_id', $user->id)->value('role');

        return $role !== null && in_array($role instanceof \BackedEnum ? $role->value : $role, $roles, true);
    }

    /** Active users (other than the requester) who could decide this type on the engagement. */
    public function eligibleDeciders(\App\Models\Engagement $engagement, ApprovalType $type, User $requester): \Illuminate\Support\Collection
    {
        $roles = config('audira.approval_engagement_roles.'.$type->value, []);

        return \App\Models\EngagementMember::with('user')->where('engagement_id', $engagement->id)
            ->whereIn('role', $roles)->where('user_id', '!=', $requester->id)->get()
            ->pluck('user')->filter(fn (?User $u) => $u && $u->isActive() && Permissions::canDecide($u, $type))->values();
    }

    /**
     * @param  bool  $reauthenticated  the caller verified the decider's password (and MFA code when
     *                                 enrolled) for this decision — required to APPROVE the types in
     *                                 config audira.reauth_approval_types
     */
    public function decide(Approval $approval, User $decider, bool $approve, ?string $comment, bool $reauthenticated = false): Approval
    {
        if ($approve && ! $reauthenticated && self::requiresReauth($approval)) {
            throw ValidationException::withMessages(['password' => 'Confirm your password to approve this request.']);
        }
        if (! $approval->isPending()) {
            throw ValidationException::withMessages(['approval' => 'This request has already been decided.']);
        }
        if ($decider->id === $approval->requested_by) {
            throw new AuthorizationException('You cannot approve or reject your own request (segregation of duties).');
        }
        if (! $this->canDecide($decider, $approval)) {
            throw new AuthorizationException('You are not authorised to decide this approval.');
        }
        if (! $approve && blank($comment)) {
            throw ValidationException::withMessages(['decision_comment' => 'A reason is required when rejecting.']);
        }

        return DB::transaction(function () use ($approval, $decider, $approve, $comment) {
            /** @var Approval $locked */
            $locked = Approval::whereKey($approval->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['approval' => 'This request has already been decided.']);
            }

            $locked->status = $approve ? ApprovalStatus::Approved : ApprovalStatus::Rejected;
            $locked->decision_comment = $comment;
            $locked->decided_by = $decider->id;
            $locked->decided_at = now();
            $locked->save();

            /** @var Model&Approvable $subject */
            $subject = $locked->approvable;
            $subject->onApprovalDecided($locked);

            $verb = $approve ? 'approved' : 'rejected';
            $this->logger->log("approval.$verb", "{$locked->type->label()} {$verb} for {$subject->approvalTitle()}", $subject, [
                'approval' => $locked->uuid, 'comment' => $comment,
            ], $decider);

            return $locked;
        });
    }

    public static function requiresReauth(Approval $approval): bool
    {
        return in_array($approval->type->value, config('audira.reauth_approval_types', []), true);
    }

    public function cancel(Approval $approval, User $actor): void
    {
        if (! $approval->isPending() || $actor->id !== $approval->requested_by) {
            throw new AuthorizationException('Only the requester can withdraw a pending request.');
        }

        DB::transaction(function () use ($approval, $actor) {
            $approval->status = ApprovalStatus::Cancelled;
            $approval->save();
            $approval->approvable->onApprovalDecided($approval);
            $this->logger->log('approval.withdrawn', "{$approval->type->label()} request withdrawn", $approval->approvable, ['approval' => $approval->uuid], $actor);
        });
    }
}
