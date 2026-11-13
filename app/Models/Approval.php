<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One row per approval request. Decisions are never overwritten; a resubmission creates a new row.
 */
class Approval extends Model
{
    use BelongsToFirm;

    protected $fillable = ['type', 'request_comment', 'snapshot', 'requested_by', 'requested_at'];

    protected function casts(): array
    {
        return [
            'type' => ApprovalType::class,
            'status' => ApprovalStatus::class,
            'snapshot' => 'array',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Approval $approval) {
            $approval->uuid ??= (string) Str::uuid();
            $approval->status ??= ApprovalStatus::Pending;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    /** Firm-level approvals plus approvals on engagements the user can see. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('engagement_id')
            ->orWhereIn('engagement_id', Engagement::query()->visibleTo($user)->select('id')));
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }
}
