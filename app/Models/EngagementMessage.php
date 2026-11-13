<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Correspondence between the audit team and the client portal. Outbound messages are drafts until
 * a person sends them (AI drafts included); inbound client messages are untrusted content.
 */
class EngagementMessage extends Model
{
    use BelongsToFirm;

    protected $fillable = ['engagement_id', 'pbc_item_id', 'direction', 'status', 'subject', 'body', 'author_id', 'author_type', 'ai_run_id', 'pbc_item_ids'];

    protected function casts(): array
    {
        return ['pbc_item_ids' => 'array', 'sent_at' => 'datetime', 'read_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (EngagementMessage $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function scopeSent(Builder $q): void
    {
        $q->where('status', 'sent');
    }
}
