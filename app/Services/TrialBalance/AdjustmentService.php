<?php

namespace App\Services\TrialBalance;

use App\Enums\AdjustmentStatus;
use App\Enums\AdjustmentType;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\SignificanceStatus;
use App\Models\Approval;
use App\Services\Approvals\ApprovalService;
use App\Enums\TbImportStatus;
use App\Enums\TbPeriod;
use App\Models\Adjustment;
use App\Models\Engagement;
use App\Models\User;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\EngagementGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Adjustment journal: draft → posted (affects the adjusted TB) → optionally voided.
 * Posting needs a reviewer-level role and a different person from the preparer (also a DB
 * constraint). Posted lines are frozen by a trigger. Material adjustments need an approved
 * material_adjustment approval whose snapshot matches the current lines (Phase 8).
 */
class AdjustmentService
{
    public function __construct(private LeadScheduleService $schedules, private LeadPaperSync $leadPapers, private ActivityLogger $logger, private ApprovalService $approvals) {}

    /**
     * @param  array{type: string, description: string, lines: list<array{account_code: string, description?: ?string, debit?: ?string, credit?: ?string}>}  $data
     */
    public function create(Engagement $engagement, array $data, User $actor): Adjustment
    {
        EngagementGuard::assertEditable($engagement);
        $type = AdjustmentType::from($data['type']);
        $lines = $this->validateLines($engagement, $data['lines']);

        return DB::transaction(function () use ($engagement, $type, $data, $lines, $actor) {
            $engagement->newQuery()->whereKey($engagement->id)->lockForUpdate()->first();
            $n = $engagement->adjustments()->where('type', $type->value)->count() + 1;
            $adj = $engagement->adjustments()->create([
                'number' => sprintf('%s-%03d', $type->prefix(), $n), 'type' => $type,
                'description' => $data['description'], 'prepared_by' => $actor->id,
            ]);
            $adj->lines()->createMany($lines);
            $this->logger->log('adjustment.created', "{$adj->number} drafted", $adj, ['total_sen' => array_sum(array_column($lines, 'debit_sen'))], $actor);

            return $adj;
        });
    }

    /** @param array{description: string, lines: list<array>} $data */
    public function update(Adjustment $adj, array $data, User $actor): Adjustment
    {
        EngagementGuard::assertEditable($adj->engagement);
        $this->assertDraft($adj);
        if ($adj->approval_status === SignificanceStatus::Pending) {
            throw ValidationException::withMessages(['adjustment' => 'This adjustment is awaiting partner approval and cannot be edited.']);
        }
        $lines = $this->validateLines($adj->engagement, $data['lines']);

        return DB::transaction(function () use ($adj, $data, $lines, $actor) {
            $adj->lines()->delete();
            $adj->lines()->createMany($lines);
            $adj->update(['description' => $data['description']]);
            if ($adj->approval_status !== null) {
                $adj->forceFill(['approval_status' => null])->save(); // changed after a decision: needs a fresh approval
            }
            $this->logger->log('adjustment.updated', "{$adj->number} edited", $adj, [], $actor);

            return $adj;
        });
    }

    public function post(Adjustment $adj, User $actor): void
    {
        $engagement = $adj->engagement;
        EngagementGuard::assertEditable($engagement);
        $this->assertDraft($adj);
        if (! Permissions::allowsOnEngagement($actor, $engagement, 'engagement.post_adjustments')) {
            throw new AuthorizationException('Only the engagement partner or manager can post adjustments.');
        }
        if ($adj->prepared_by === $actor->id) {
            throw new AuthorizationException('The preparer of an adjustment cannot post it.');
        }
        // Re-validate against the TB as it is now.
        $this->validateLines($engagement, $adj->lines->map(fn ($l) => [
            'account_code' => $l->account_code, 'debit' => (string) ($l->debit_sen / 100), 'credit' => (string) ($l->credit_sen / 100), 'description' => $l->description,
        ])->all());

        $threshold = $this->materialThreshold($engagement);
        if ($this->requiresApproval($adj, $threshold)) {
            $hash = $adj->linesHash();
            $approved = $adj->approvals()->where('type', ApprovalType::MaterialAdjustment->value)->where('status', ApprovalStatus::Approved->value)->get()
                ->contains(fn ($a) => ($a->snapshot['lines_sha256'] ?? null) === $hash);
            if (! $approved) {
                throw ValidationException::withMessages(['adjustment' => $threshold === null
                    ? 'Materiality has not been approved, so every adjustment needs engagement-partner approval before posting.'
                    : 'This is a material adjustment (total RM '.amount($adj->totalDebitSen(), false).' is at or above the threshold of RM '.amount($threshold, false).'). It needs engagement-partner approval before posting.']);
            }
        }

        DB::transaction(function () use ($adj, $actor, $engagement) {
            $adj->forceFill(['status' => AdjustmentStatus::Posted, 'posted_by' => $actor->id, 'posted_at' => now()])->save();
            $this->logger->log('adjustment.posted', "{$adj->number} posted to the adjusted trial balance", $adj, ['total_sen' => $adj->totalDebitSen()], $actor);
            $this->leadPapers->refresh($engagement, $actor, "Adjustment {$adj->number} posted");
        });
    }

    /**
     * The amount at or above which an adjustment is "material" and needs partner approval, from
     * config audira.material_adjustment.basis against the approved materiality. Null = no approved
     * materiality, in which case every adjustment is treated as material (conservative default).
     */
    public function materialThreshold(\App\Models\Engagement $engagement): ?int
    {
        $m = $engagement->currentMateriality();
        if (! $m) {
            return null;
        }

        return match (config('audira.material_adjustment.basis', 'pm')) {
            'om' => (int) $m->om_sen,
            'ct' => (int) $m->ct_sen,
            'all' => 0,
            default => (int) $m->pm_sen,
        };
    }

    public function requiresApproval(Adjustment $adj, ?int $threshold = null, bool $resolve = false): bool
    {
        if ($resolve) {
            $threshold = $this->materialThreshold($adj->engagement);
        }

        return $threshold === null || $adj->totalDebitSen() >= $threshold;
    }

    /** Ask the engagement partner to approve a material adjustment before it is posted. */
    public function requestApproval(Adjustment $adj, User $actor, ?string $comment): Approval
    {
        $engagement = $adj->engagement;
        EngagementGuard::assertEditable($engagement);
        $this->assertDraft($adj);
        if (! Permissions::allowsOnEngagement($actor, $engagement, 'engagement.prepare_adjustments')) {
            throw new AuthorizationException('Not allowed.');
        }
        if (! $this->requiresApproval($adj, resolve: true)) {
            throw ValidationException::withMessages(['adjustment' => 'This adjustment is below the material-adjustment threshold and does not need approval.']);
        }
        if ($adj->approval_status === SignificanceStatus::Approved) {
            throw ValidationException::withMessages(['adjustment' => 'Already approved.']);
        }

        return DB::transaction(function () use ($adj, $actor, $comment, $engagement) {
            $approval = $this->approvals->request($adj, ApprovalType::MaterialAdjustment, $actor, $comment, [
                'number' => $adj->number, 'type' => $adj->type->value, 'description' => $adj->description,
                'total_sen' => $adj->totalDebitSen(), 'threshold_sen' => $this->materialThreshold($engagement),
                'basis' => config('audira.material_adjustment.basis', 'pm'), 'lines_sha256' => $adj->linesHash(),
                'lines' => $adj->lines->map(fn ($l) => ['account' => $l->account_code, 'debit_sen' => (int) $l->debit_sen, 'credit_sen' => (int) $l->credit_sen])->all(),
            ]);
            $adj->forceFill(['approval_status' => SignificanceStatus::Pending])->save();

            return $approval;
        });
    }

    public function applyDecision(Adjustment $adj, Approval $approval): void
    {
        $adj->forceFill(['approval_status' => match ($approval->status) {
            ApprovalStatus::Approved => SignificanceStatus::Approved,
            ApprovalStatus::Rejected => SignificanceStatus::Rejected,
            default => null,
        }])->save();
    }

    public function void(Adjustment $adj, string $reason, User $actor): void
    {
        $engagement = $adj->engagement;
        EngagementGuard::assertEditable($engagement);
        if ($adj->status === AdjustmentStatus::Voided) {
            throw ValidationException::withMessages(['adjustment' => 'Already voided.']);
        }
        $wasPosted = $adj->status === AdjustmentStatus::Posted;
        $perm = $wasPosted ? 'engagement.post_adjustments' : 'engagement.prepare_adjustments';
        if (! Permissions::allowsOnEngagement($actor, $engagement, $perm)) {
            throw new AuthorizationException($wasPosted ? 'Only the engagement partner or manager can void a posted adjustment.' : 'Not allowed.');
        }

        DB::transaction(function () use ($adj, $reason, $actor, $wasPosted, $engagement) {
            $adj->forceFill(['status' => AdjustmentStatus::Voided, 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason])->save();
            $this->logger->log('adjustment.voided', "{$adj->number} voided: {$reason}", $adj, ['was_posted' => $wasPosted], $actor);
            if ($wasPosted) {
                $this->leadPapers->refresh($engagement, $actor, "Adjustment {$adj->number} voided");
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $input
     * @return list<array{account_code: string, description: ?string, debit_sen: int, credit_sen: int, sort_order: int}>
     */
    private function validateLines(Engagement $engagement, array $input): array
    {
        $hasCurrent = $engagement->tbImports()->where('period', TbPeriod::Current->value)->where('status', TbImportStatus::Active->value)->exists();
        if (! $hasCurrent) {
            throw ValidationException::withMessages(['lines' => 'Import the current-year trial balance before recording adjustments.']);
        }
        $accounts = $this->schedules->accounts($engagement)->filter(fn ($a) => $a['in_cy']);

        $lines = [];
        $errors = [];
        foreach (array_values($input) as $i => $row) {
            $n = $i + 1;
            $code = trim((string) ($row['account_code'] ?? ''));
            $debitRaw = (string) ($row['debit'] ?? '');
            $creditRaw = (string) ($row['credit'] ?? '');
            if ($code === '' && trim($debitRaw.$creditRaw) === '') {
                continue; // blank line in the form
            }
            try {
                $dr = AmountParser::toSen($debitRaw);
                $cr = AmountParser::toSen($creditRaw);
            } catch (InvalidArgumentException $e) {
                $errors[] = "Line {$n}: ".$e->getMessage().'.';

                continue;
            }
            if (! $accounts->has($code)) {
                $errors[] = "Line {$n}: account \"{$code}\" is not in the current-year trial balance.";
            }
            if ($dr < 0 || $cr < 0 || ($dr > 0) === ($cr > 0)) {
                $errors[] = "Line {$n}: enter a positive debit or a positive credit (not both).";
            }
            $lines[] = ['account_code' => $code, 'description' => trim((string) ($row['description'] ?? '')) ?: null, 'debit_sen' => $dr, 'credit_sen' => $cr, 'sort_order' => $n];
        }

        if (count($lines) < 2) {
            $errors[] = 'An adjustment needs at least two lines.';
        }
        $dr = array_sum(array_column($lines, 'debit_sen'));
        $cr = array_sum(array_column($lines, 'credit_sen'));
        if (! $errors && $dr !== $cr) {
            $errors[] = 'Debits ('.AmountParser::format($dr).') and credits ('.AmountParser::format($cr).') must be equal.';
        }
        if ($errors) {
            throw ValidationException::withMessages(['lines' => $errors]);
        }

        return $lines;
    }

    private function assertDraft(Adjustment $adj): void
    {
        if ($adj->status !== AdjustmentStatus::Draft) {
            throw ValidationException::withMessages(['adjustment' => 'Only draft adjustments can be changed or posted.']);
        }
    }
}
