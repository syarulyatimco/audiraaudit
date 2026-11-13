<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiRun extends Model
{
    use BelongsToFirm;

    public const ACTIVE = ['queued', 'running'];

    protected $fillable = ['engagement_id', 'agent', 'task', 'requested_by', 'input'];

    protected function casts(): array
    {
        return ['input' => 'array', 'output' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (AiRun $r) {
            $r->uuid ??= (string) Str::uuid();
            $r->status ??= 'queued';
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

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AiRunStep::class)->orderBy('seq');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function isUsable(): bool
    {
        return in_array($this->status, ['completed', 'needs_review'], true);
    }

    public function statusTone(): string
    {
        return ['queued' => 'neutral', 'running' => 'info', 'completed' => 'success', 'needs_review' => 'warn', 'failed' => 'danger', 'cancelled' => 'neutral'][$this->status] ?? 'neutral';
    }

    public function statusLabel(): string
    {
        return ['queued' => 'Queued', 'running' => 'Running', 'completed' => 'Completed', 'needs_review' => 'Needs review', 'failed' => 'Failed', 'cancelled' => 'Cancelled'][$this->status] ?? $this->status;
    }
}
