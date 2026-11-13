<?php

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\CorrectionStatus;
use App\Enums\DeficiencySeverity;
use App\Enums\FindingStatus;
use App\Enums\FindingType;
use App\Enums\MisstatementClass;
use App\Enums\SignificanceStatus;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * An audit finding: a misstatement (ISA 450), a deficiency in internal control (ISA 265) or
 * another matter. Misstatement amounts are recorded minus audited (positive = overstatement of
 * the FSLI). Whether a finding is SIGNIFICANT is the team's judgement and is approved by the
 * engagement partner (CLAUDE.md §9).
 */
class Finding extends Model implements Approvable
{
    use BelongsToFirm;

    protected $fillable = [
        'engagement_id', 'reference', 'type', 'title', 'description', 'cause', 'recommendation', 'engagement_fsli_id',
        'procedure_id', 'sampling_plan_id', 'sample_item_id', 'misstatement_class', 'amount_sen', 'correction_status',
        'uncorrected_reason', 'adjustment_id', 'deficiency_severity', 'report_to_management', 'management_response',
        'is_significant', 'source', 'ai_run_id', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => FindingType::class,
            'misstatement_class' => MisstatementClass::class,
            'correction_status' => CorrectionStatus::class,
            'deficiency_severity' => DeficiencySeverity::class,
            'significance_status' => SignificanceStatus::class,
            'status' => FindingStatus::class,
            'amount_sen' => 'integer',
            'is_significant' => 'boolean',
            'report_to_management' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Finding $f) {
            $f->uuid ??= (string) Str::uuid();
            $f->status ??= FindingStatus::Open;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function fsli(): BelongsTo
    {
        return $this->belongsTo(EngagementFsli::class, 'engagement_fsli_id');
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    public function samplingPlan(): BelongsTo
    {
        return $this->belongsTo(SamplingPlan::class);
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(Adjustment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable')->latest('requested_at');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', '!=', FindingStatus::Withdrawn->value);
    }

    public function isMisstatement(): bool
    {
        return $this->type === FindingType::Misstatement;
    }

    /** Uncorrected misstatements accumulate on the summary of audit differences. */
    public function isUncorrected(): bool
    {
        return $this->isMisstatement() && $this->status !== FindingStatus::Withdrawn && $this->correction_status !== CorrectionStatus::Corrected;
    }

    public function needsApproval(): bool
    {
        return $this->is_significant && $this->status !== FindingStatus::Withdrawn && $this->significance_status !== SignificanceStatus::Approved;
    }

    public function approvalTitle(): string
    {
        return "Significant finding {$this->reference}: {$this->title}";
    }

    public function approvalUrl(): string
    {
        return route('findings.show', [$this->engagement, $this]);
    }

    public function approvalEngagement(): ?Engagement
    {
        return $this->engagement;
    }

    public function onApprovalDecided(Approval $approval): void
    {
        app(\App\Services\Findings\FindingService::class)->applyDecision($this, $approval);
    }
}
