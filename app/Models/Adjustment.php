<?php

namespace App\Models;

use App\Enums\AdjustmentStatus;
use App\Enums\AdjustmentType;
use App\Contracts\Approvable;
use App\Enums\SignificanceStatus;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * A proposed journal to the trial balance. Material adjustments (total at or above the threshold in
 * config audira.material_adjustment) need engagement-partner approval before posting (CLAUDE.md §9).
 */
class Adjustment extends Model implements Approvable
{
    use BelongsToFirm;

    protected $fillable = ['engagement_id', 'number', 'type', 'description', 'prepared_by'];

    protected function casts(): array
    {
        return [
            'type' => AdjustmentType::class,
            'status' => AdjustmentStatus::class,
            'approval_status' => SignificanceStatus::class,
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Adjustment $a) {
            $a->uuid ??= (string) Str::uuid();
            $a->status ??= AdjustmentStatus::Draft;
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

    public function lines(): HasMany
    {
        return $this->hasMany(AdjustmentLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function totalDebitSen(): int
    {
        return (int) $this->lines->sum('debit_sen');
    }

    public function totalCreditSen(): int
    {
        return (int) $this->lines->sum('credit_sen');
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable')->latest('requested_at');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    /** Stable fingerprint of the journal lines, recorded in the approval snapshot and checked at posting. */
    public function linesHash(): string
    {
        return hash('sha256', json_encode($this->lines->map(fn ($l) => [$l->account_code, (int) $l->debit_sen, (int) $l->credit_sen])->values()->all()));
    }

    public function approvalTitle(): string
    {
        return "Material adjustment {$this->number}: ".\Illuminate\Support\Str::limit($this->description, 80);
    }

    public function approvalUrl(): string
    {
        return route('adjustments.show', [$this->engagement, $this]);
    }

    public function approvalEngagement(): ?Engagement
    {
        return $this->engagement;
    }

    public function onApprovalDecided(Approval $approval): void
    {
        app(\App\Services\TrialBalance\AdjustmentService::class)->applyDecision($this, $approval);
    }
}
