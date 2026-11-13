<?php

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\SampleTestType;
use App\Enums\SamplingApproach;
use App\Enums\SamplingMethod;
use App\Enums\SamplingStatus;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * A sampling plan (CLAUDE.md §7): population with completeness reconciliation, documented sample
 * size, engagement-partner/manager approval before selection, reproducible selection (seed +
 * algorithm + population hash + per-item log), results and evaluation.
 */
class SamplingPlan extends Model implements Approvable
{
    use BelongsToFirm;

    protected $guarded = ['id', 'uuid', 'firm_id', 'status', 'selection_seed', 'selected_at', 'selected_by', 'evaluation'];

    protected function casts(): array
    {
        return [
            'test_type' => SampleTestType::class,
            'approach' => SamplingApproach::class,
            'method' => SamplingMethod::class,
            'status' => SamplingStatus::class,
            'population_count' => 'integer',
            'population_value_sen' => 'integer',
            'control_total_sen' => 'integer',
            'tolerable_misstatement_sen' => 'integer',
            'expected_misstatement_sen' => 'integer',
            'key_item_threshold_sen' => 'integer',
            'confidence_level' => 'float',
            'expansion_factor' => 'float',
            'assurance_factor' => 'float',
            'tolerable_deviation_rate' => 'float',
            'expected_deviation_rate' => 'float',
            'strata' => 'array',
            'size_calculation' => 'array',
            'evaluation' => 'array',
            'selection_seed' => 'integer',
            'selected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SamplingPlan $p) {
            $p->uuid ??= (string) Str::uuid();
            $p->status ??= SamplingStatus::Draft;
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

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    public function fsli(): BelongsTo
    {
        return $this->belongsTo(EngagementFsli::class, 'engagement_fsli_id');
    }

    public function populationItems(): HasMany
    {
        return $this->hasMany(PopulationItem::class)->orderBy('seq');
    }

    public function sampleItems(): HasMany
    {
        return $this->hasMany(SampleItem::class)->orderBy('selection_order');
    }

    /** Alias used by scoped route binding of {item}. */
    public function items(): HasMany
    {
        return $this->hasMany(SampleItem::class);
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable')->latest('requested_at');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [SamplingStatus::Draft, SamplingStatus::Rejected], true);
    }

    /** Difference between the population total and the control total it must reconcile to. */
    public function reconciliationDifference(): ?int
    {
        return $this->control_total_sen === null ? null : $this->population_value_sen - $this->control_total_sen;
    }

    // --- Approvable ---------------------------------------------------------------------------

    public function approvalTitle(): string
    {
        return "Sampling plan {$this->reference} {$this->title} · {$this->engagement->code}";
    }

    public function approvalUrl(): string
    {
        return route('sampling.show', [$this->engagement, $this]);
    }

    public function approvalEngagement(): ?Engagement
    {
        return $this->engagement;
    }

    public function onApprovalDecided(Approval $approval): void
    {
        app(\App\Services\Sampling\SamplingService::class)->applyDecision($this, $approval);
    }
}
