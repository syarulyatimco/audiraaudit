<?php

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\RiskLevel;
use App\Enums\RiskRating;
use App\Enums\SignificanceStatus;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * A risk of material misstatement at financial-statement or assertion level. Inherent risk is
 * placed on a spectrum from likelihood × magnitude; whether a risk is SIGNIFICANT is the team's
 * judgement and must be approved by the engagement partner (CLAUDE.md §9).
 */
class Risk extends Model implements Approvable
{
    use BelongsToFirm;

    protected $fillable = [
        'engagement_id', 'reference', 'title', 'description', 'level', 'assertions', 'inherent_factors', 'likelihood', 'magnitude',
        'is_significant', 'is_fraud_risk', 'presumed_key', 'rebuttal_reason', 'controls_reliance', 'controls_description',
        'planned_response', 'source', 'ai_run_id', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'level' => RiskLevel::class,
            'likelihood' => RiskRating::class,
            'magnitude' => RiskRating::class,
            'significance_status' => SignificanceStatus::class,
            'assertions' => 'array',
            'inherent_factors' => 'array',
            'is_significant' => 'boolean',
            'is_fraud_risk' => 'boolean',
            'controls_reliance' => 'boolean',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Risk $r) {
            $r->uuid ??= (string) Str::uuid();
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

    public function fslis(): BelongsToMany
    {
        return $this->belongsToMany(EngagementFsli::class, 'risk_fslis')->orderBy('sort_order');
    }

    public function procedures(): BelongsToMany
    {
        return $this->belongsToMany(Procedure::class, 'procedure_risks')->orderBy('reference');
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable')->latest('requested_at');
    }

    public function scopeActive(Builder $q): void
    {
        $q->whereNull('withdrawn_at');
    }

    public function isActive(): bool
    {
        return $this->withdrawn_at === null;
    }

    /** Position on the inherent-risk spectrum (display aid; the team's judgement is recorded separately). */
    public function inherentRisk(): string
    {
        $score = $this->likelihood->score() + $this->magnitude->score();

        return match (true) {
            $score >= 5 => 'high',
            $score === 4 => 'medium',
            default => 'low',
        };
    }

    public function inherentRiskLabel(): string
    {
        return ['high' => 'Higher', 'medium' => 'Moderate', 'low' => 'Lower'][$this->inherentRisk()];
    }

    /** Significant risk that still needs (re-)approval. */
    public function needsApproval(): bool
    {
        return $this->isActive() && $this->is_significant && $this->significance_status !== SignificanceStatus::Approved;
    }

    public function isPresumed(): bool
    {
        return $this->presumed_key !== null;
    }

    public function rebutted(): bool
    {
        return filled($this->rebuttal_reason);
    }

    // --- Approvable ---------------------------------------------------------------------------

    public function approvalTitle(): string
    {
        return "Significant risk {$this->reference} {$this->title} · {$this->engagement->code}";
    }

    public function approvalUrl(): string
    {
        return route('risks.show', [$this->engagement, $this]);
    }

    public function approvalEngagement(): ?Engagement
    {
        return $this->engagement;
    }

    public function onApprovalDecided(Approval $approval): void
    {
        app(\App\Services\Planning\RiskService::class)->applyDecision($this, $approval);
    }
}
