<?php

namespace App\Services\Findings;

use App\Enums\AdjustmentStatus;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\CorrectionStatus;
use App\Enums\DeficiencySeverity;
use App\Enums\FindingStatus;
use App\Enums\FindingType;
use App\Enums\MisstatementClass;
use App\Enums\SampleTestType;
use App\Enums\SamplingStatus;
use App\Enums\SignificanceStatus;
use App\Models\Adjustment;
use App\Models\AiRun;
use App\Models\Approval;
use App\Models\Engagement;
use App\Models\Finding;
use App\Models\SamplingPlan;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\TrialBalance\AmountParser;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\EngagementGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Findings register: misstatements (ISA 450), control deficiencies (ISA 265) and other matters.
 * Misstatements carry a class (factual / judgemental / projected), an amount (recorded − audited)
 * and a correction status; uncorrected misstatements need management's reason and feed the
 * summary of audit differences. Significance is the team's call, approved by the engagement
 * partner. Nothing here decides the audit opinion.
 */
class FindingService
{
    public function __construct(private ApprovalService $approvals, private ActivityLogger $logger) {}

    public function create(Engagement $engagement, array $data, User $actor, ?AiRun $aiRun = null, string $source = 'manual'): Finding
    {
        EngagementGuard::assertEditable($engagement);
        $this->authorise($actor, $engagement);
        $attrs = $this->validated($engagement, $data);

        return DB::transaction(function () use ($engagement, $attrs, $actor, $aiRun, $source) {
            $f = new Finding($attrs + [
                'engagement_id' => $engagement->id, 'reference' => $this->nextReference($engagement),
                'source' => $aiRun ? 'ai' : $source, 'ai_run_id' => $aiRun?->id, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $f->correction_status ??= $f->type === FindingType::Misstatement ? CorrectionStatus::Proposed : null;
            $f->save();
            $this->logger->log('finding.created', "Finding {$f->reference} {$f->title} recorded", $f, ['type' => $f->type->value, 'amount_sen' => $f->amount_sen, 'source' => $f->source], $actor);

            return $f;
        });
    }

    public function update(Finding $f, array $data, User $actor): Finding
    {
        EngagementGuard::assertEditable($f->engagement);
        $this->authorise($actor, $f->engagement);
        $this->assertChangeable($f);
        $attrs = $this->validated($f->engagement, $data + ['type' => $f->type->value], $f);

        return DB::transaction(function () use ($f, $attrs, $actor) {
            $f->fill($attrs + ['updated_by' => $actor->id]);
            if (! $f->is_significant) {
                $f->significance_status = null;
            } elseif ($f->isDirty() && $f->significance_status !== null) {
                $f->significance_status = null; // changed after the partner's decision: needs a fresh approval
            }
            $f->save();
            $this->logger->log('finding.updated', "Finding {$f->reference} updated", $f, ['fields' => array_keys($f->getChanges())], $actor);

            return $f;
        });
    }

    /**
     * Record the misstatements from an evaluated monetary sample: one FACTUAL finding for the
     * misstatements actually found (key items and sampled items) and one PROJECTED finding for the
     * remainder of the projection. Amounts come from the stored evaluation, not from anyone's input.
     *
     * @return list<Finding>
     */
    public function fromSample(SamplingPlan $plan, User $actor): array
    {
        $engagement = $plan->engagement;
        EngagementGuard::assertEditable($engagement);
        $this->authorise($actor, $engagement);
        if ($plan->status !== SamplingStatus::Evaluated || $plan->test_type !== SampleTestType::Monetary) {
            throw ValidationException::withMessages(['plan' => 'Findings can be recorded from an evaluated monetary sample.']);
        }
        if (! $plan->engagement_fsli_id) {
            throw ValidationException::withMessages(['plan' => 'Link the sampling plan to a financial statement line item first.']);
        }
        if (Finding::where('sampling_plan_id', $plan->id)->where('source', 'sample')->active()->exists()) {
            throw ValidationException::withMessages(['plan' => 'Findings have already been recorded from this sample.']);
        }
        $items = $plan->sampleItems()->with('populationItem')->where('result', 'exception')->get();
        $factual = (int) $items->sum(fn ($i) => $i->misstatementSen());
        $total = (int) ($plan->evaluation['projected_total_sen'] ?? $factual);
        $projected = $total - $factual;
        if ($factual === 0 && $projected === 0) {
            throw ValidationException::withMessages(['plan' => 'The sample found no misstatement to record.']);
        }

        return DB::transaction(function () use ($plan, $actor, $engagement, $items, $factual, $projected) {
            $out = [];
            $base = ['type' => FindingType::Misstatement->value, 'engagement_fsli_id' => $plan->engagement_fsli_id,
                'procedure_id' => $plan->procedure_id, 'sampling_plan_id' => $plan->id, 'is_significant' => false];
            if ($factual !== 0) {
                $out[] = $this->create($engagement, $base + [
                    'misstatement_class' => MisstatementClass::Factual->value, 'amount_sen' => $factual,
                    'title' => "Misstatements found in sample {$plan->reference}",
                    'description' => "Exceptions found in testing sample {$plan->reference} ({$plan->title}):\n".$items->map(fn ($i) => "• {$i->populationItem->item_ref}: recorded ".amount($i->populationItem->amount_sen, false).', audited '.amount($i->audited_amount_sen, false).($i->result_note ? " — {$i->result_note}" : ''))->implode("\n"),
                ], $actor, null, 'sample');
            }
            if ($projected !== 0) {
                $out[] = $this->create($engagement, $base + [
                    'misstatement_class' => MisstatementClass::Projected->value, 'amount_sen' => $projected,
                    'title' => "Projected misstatement from sample {$plan->reference}",
                    'description' => "Projection of the sample misstatement to the untested population using: {$plan->evaluation['method']}. This is the auditor's best estimate of misstatement in the untested items; it is not an identified error.",
                ], $actor, null, 'sample');
            }

            return $out;
        });
    }

    /** Record management's response on a misstatement: corrected (by a posted adjustment) or uncorrected (with reason). */
    public function setCorrection(Finding $f, string $status, ?string $reason, ?string $adjustmentUuid, User $actor): void
    {
        EngagementGuard::assertEditable($f->engagement);
        $this->authorise($actor, $f->engagement);
        if (! $f->isMisstatement() || $f->status === FindingStatus::Withdrawn) {
            throw ValidationException::withMessages(['correction_status' => 'Only an active misstatement has a correction status.']);
        }
        $status = CorrectionStatus::tryFrom($status) ?? throw ValidationException::withMessages(['correction_status' => 'Choose a status.']);
        $adjustment = null;
        if ($status === CorrectionStatus::Corrected) {
            $adjustment = $adjustmentUuid ? Adjustment::where('engagement_id', $f->engagement_id)->where('uuid', $adjustmentUuid)->first() : null;
            if (! $adjustment || $adjustment->status !== AdjustmentStatus::Posted) {
                throw ValidationException::withMessages(['adjustment' => 'Link the posted adjustment that corrects this misstatement.']);
            }
        }
        if ($status === CorrectionStatus::Uncorrected && blank($reason)) {
            throw ValidationException::withMessages(['uncorrected_reason' => "Record management's reason for not correcting the misstatement."]);
        }
        $f->forceFill([
            'correction_status' => $status, 'adjustment_id' => $adjustment?->id,
            'uncorrected_reason' => $status === CorrectionStatus::Uncorrected ? trim($reason) : null, 'updated_by' => $actor->id,
        ])->save();
        $this->logger->log('finding.correction', "Finding {$f->reference} marked {$status->label()}", $f, ['adjustment' => $adjustment?->number, 'reason' => $f->uncorrected_reason], $actor);
    }

    public function submitSignificance(Finding $f, User $actor, ?string $comment): Approval
    {
        EngagementGuard::assertEditable($f->engagement);
        $this->authorise($actor, $f->engagement);
        if (! $f->is_significant || $f->status === FindingStatus::Withdrawn) {
            throw ValidationException::withMessages(['finding' => 'Only an active significant finding is submitted for approval.']);
        }
        if (in_array($f->significance_status, [SignificanceStatus::Pending, SignificanceStatus::Approved], true)) {
            throw ValidationException::withMessages(['finding' => 'This finding is already approved or awaiting approval.']);
        }

        return DB::transaction(function () use ($f, $actor, $comment) {
            $approval = $this->approvals->request($f, ApprovalType::SignificantFinding, $actor, $comment, [
                'reference' => $f->reference, 'type' => $f->type->value, 'title' => $f->title, 'description' => $f->description,
                'class' => $f->misstatement_class?->value, 'amount_sen' => $f->amount_sen, 'fsli' => $f->fsli?->code,
                'severity' => $f->deficiency_severity?->value, 'correction_status' => $f->correction_status?->value,
            ]);
            $f->forceFill(['significance_status' => SignificanceStatus::Pending])->save();

            return $approval;
        });
    }

    public function applyDecision(Finding $f, Approval $approval): void
    {
        $f->forceFill(['significance_status' => match ($approval->status) {
            ApprovalStatus::Approved => SignificanceStatus::Approved,
            ApprovalStatus::Rejected => SignificanceStatus::Rejected,
            default => null,
        }])->save();
    }

    public function resolve(Finding $f, string $resolution, User $actor): void
    {
        $this->retire($f, FindingStatus::Resolved, $resolution, $actor);
    }

    public function withdraw(Finding $f, string $reason, User $actor): void
    {
        $this->retire($f, FindingStatus::Withdrawn, $reason, $actor);
    }

    private function retire(Finding $f, FindingStatus $to, string $text, User $actor): void
    {
        EngagementGuard::assertEditable($f->engagement);
        $this->authorise($actor, $f->engagement);
        $this->assertChangeable($f);
        if ($f->status !== FindingStatus::Open) {
            throw ValidationException::withMessages(['finding' => 'Only an open finding can be resolved or withdrawn.']);
        }
        if (blank($text)) {
            throw ValidationException::withMessages(['resolution' => 'Explain the resolution or the reason.']);
        }
        if ($to === FindingStatus::Resolved && $f->isMisstatement() && $f->correction_status === CorrectionStatus::Proposed) {
            throw ValidationException::withMessages(['resolution' => 'Record whether management corrected the misstatement before resolving it.']);
        }
        $f->forceFill(['status' => $to, 'resolution' => trim($text), 'updated_by' => $actor->id])->save();
        $this->logger->log('finding.'.$to->value, "Finding {$f->reference} {$to->value}", $f, ['text' => trim($text)], $actor);
    }

    /** @return array<string, mixed> */
    private function validated(Engagement $engagement, array $data, ?Finding $existing = null): array
    {
        $errors = [];
        $type = FindingType::tryFrom((string) ($data['type'] ?? '')) ?? null;
        if (! $type) {
            $errors['type'] = 'Choose a finding type.';
        }
        foreach (['title' => 200, 'description' => 10000] as $k => $max) {
            if (blank($data[$k] ?? null)) {
                $errors[$k] = 'Required.';
            } elseif (mb_strlen($data[$k]) > $max) {
                $errors[$k] = "At most {$max} characters.";
            }
        }
        $fsliId = filled($data['engagement_fsli_id'] ?? null) ? (int) $data['engagement_fsli_id'] : null;
        if ($fsliId && ! $engagement->fslis()->whereKey($fsliId)->exists()) {
            $errors['engagement_fsli_id'] = 'Unknown line item.';
        }
        $procId = filled($data['procedure_id'] ?? null) ? (int) $data['procedure_id'] : null;
        if ($procId && ! $engagement->procedures()->whereKey($procId)->exists()) {
            $errors['procedure_id'] = 'Unknown procedure.';
        }
        $planId = filled($data['sampling_plan_id'] ?? null) ? (int) $data['sampling_plan_id'] : null;
        if ($planId && ! SamplingPlan::where('engagement_id', $engagement->id)->whereKey($planId)->exists()) {
            $errors['sampling_plan_id'] = 'Unknown sampling plan.';
        }

        $attrs = [
            'type' => $type, 'title' => trim((string) ($data['title'] ?? '')), 'description' => trim((string) ($data['description'] ?? '')),
            'cause' => filled($data['cause'] ?? null) ? trim($data['cause']) : null,
            'recommendation' => filled($data['recommendation'] ?? null) ? trim($data['recommendation']) : null,
            'engagement_fsli_id' => $fsliId, 'procedure_id' => $procId, 'sampling_plan_id' => $planId,
            'is_significant' => filter_var($data['is_significant'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'misstatement_class' => null, 'amount_sen' => null, 'deficiency_severity' => null, 'report_to_management' => false,
            'management_response' => filled($data['management_response'] ?? null) ? trim($data['management_response']) : null,
        ];
        if (! $existing && filled($data['sample_item_id'] ?? null)) {
            $attrs['sample_item_id'] = (int) $data['sample_item_id'];
        }

        if ($type === FindingType::Misstatement) {
            $attrs['misstatement_class'] = MisstatementClass::tryFrom((string) ($data['misstatement_class'] ?? ''));
            if (! $attrs['misstatement_class']) {
                $errors['misstatement_class'] = 'Classify the misstatement as factual, judgemental or projected.';
            }
            if (! $fsliId) {
                $errors['engagement_fsli_id'] = 'Choose the line item the misstatement affects.';
            }
            if (is_int($data['amount_sen'] ?? null)) {
                $attrs['amount_sen'] = $data['amount_sen'];
            } else {
                try {
                    $attrs['amount_sen'] = filled($data['amount'] ?? null) ? AmountParser::toSen((string) $data['amount']) : null;
                } catch (\Throwable) {
                    $attrs['amount_sen'] = null;
                }
            }
            if (! $attrs['amount_sen']) {
                $errors['amount'] = 'Enter the misstatement (recorded minus audited; negative for an understatement).';
            }
        } elseif ($type === FindingType::ControlDeficiency) {
            $attrs['deficiency_severity'] = DeficiencySeverity::tryFrom((string) ($data['deficiency_severity'] ?? ''));
            if (! $attrs['deficiency_severity']) {
                $errors['deficiency_severity'] = 'Evaluate the deficiency as a deficiency or a significant deficiency.';
            }
            // Significant deficiencies are communicated in writing to those charged with governance; others where relevant to management.
            $attrs['report_to_management'] = $attrs['deficiency_severity'] === DeficiencySeverity::Significant || filter_var($data['report_to_management'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $attrs;
    }

    private function assertChangeable(Finding $f): void
    {
        if ($f->status !== FindingStatus::Open) {
            throw ValidationException::withMessages(['finding' => 'This finding is closed.']);
        }
        if ($f->significance_status === SignificanceStatus::Pending) {
            throw ValidationException::withMessages(['finding' => 'This finding is awaiting partner approval.']);
        }
    }

    private function nextReference(Engagement $engagement): string
    {
        $n = Finding::where('engagement_id', $engagement->id)->count() + 1;
        do {
            $ref = sprintf('F%02d', $n++);
        } while (Finding::where('engagement_id', $engagement->id)->where('reference', $ref)->exists());

        return $ref;
    }

    private function authorise(User $actor, Engagement $engagement): void
    {
        if (! Permissions::allowsOnEngagement($actor, $engagement, 'engagement.manage_findings')) {
            throw new AuthorizationException('Your role on this engagement does not allow managing findings.');
        }
    }
}
