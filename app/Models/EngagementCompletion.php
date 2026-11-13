<?php

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\SignificanceStatus;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * Completion record of an engagement: the team's final conclusions (approved by the engagement
 * partner), the completion approval and the engagement quality review.
 */
class EngagementCompletion extends Model implements Approvable
{
    use BelongsToFirm;

    protected $fillable = ['engagement_id', 'misstatements_conclusion', 'going_concern_conclusion', 'overall_conclusion', 'updated_by'];

    protected function casts(): array
    {
        return [
            'conclusions_status' => SignificanceStatus::class,
            'completion_status' => SignificanceStatus::class,
            'eqr_completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (EngagementCompletion $c) => $c->uuid ??= (string) Str::uuid());
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function eqrReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'eqr_completed_by');
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable')->latest('requested_at');
    }

    public function conclusionsHash(): string
    {
        return hash('sha256', json_encode([$this->misstatements_conclusion, $this->going_concern_conclusion, $this->overall_conclusion]));
    }

    public function approvalTitle(): string
    {
        return 'Completion of '.$this->engagement->code.' — '.$this->engagement->client->name;
    }

    public function approvalUrl(): string
    {
        return route('completion.index', $this->engagement);
    }

    public function approvalEngagement(): ?Engagement
    {
        return $this->engagement;
    }

    public function onApprovalDecided(Approval $approval): void
    {
        app(\App\Services\Completion\CompletionService::class)->applyDecision($this, $approval);
    }
}
