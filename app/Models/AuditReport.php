<?php

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\AuditOpinion;
use App\Enums\SignificanceStatus;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * The auditor's report. The opinion is proposed by the team and decided by the engagement partner;
 * sign-off needs a separate, re-authenticated approval and freezes the report (DB trigger).
 * AI never decides or drafts the opinion.
 */
class AuditReport extends Model implements Approvable
{
    use BelongsToFirm;

    protected $fillable = ['engagement_id', 'opinion', 'basis_for_modification', 'going_concern_uncertainty', 'going_concern_paragraph', 'emphasis_of_matter', 'other_matter', 'report_date', 'body', 'updated_by'];

    protected function casts(): array
    {
        return [
            'opinion' => AuditOpinion::class,
            'opinion_status' => SignificanceStatus::class,
            'signoff_status' => SignificanceStatus::class,
            'going_concern_uncertainty' => 'boolean',
            'report_date' => 'date',
            'signed_at' => 'datetime',
            'fs_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (AuditReport $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable')->latest('requested_at');
    }

    public function isSigned(): bool
    {
        return $this->status === 'signed';
    }

    public function bodyHash(): string
    {
        return hash('sha256', (string) $this->body);
    }

    public function opinionHash(): string
    {
        return hash('sha256', json_encode([$this->opinion?->value, $this->basis_for_modification, $this->going_concern_uncertainty, $this->going_concern_paragraph, $this->emphasis_of_matter, $this->other_matter]));
    }

    public function approvalTitle(): string
    {
        return 'Audit report for '.$this->engagement->client->name.' — '.($this->opinion?->label() ?? 'opinion not set');
    }

    public function approvalUrl(): string
    {
        return route('reporting.report', $this->engagement);
    }

    public function approvalEngagement(): ?Engagement
    {
        return $this->engagement;
    }

    public function onApprovalDecided(Approval $approval): void
    {
        app(\App\Services\Reporting\ReportService::class)->applyDecision($this, $approval);
    }
}
