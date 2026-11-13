<?php

namespace App\Models;

use App\Enums\PbcCategory;
use App\Enums\PbcStatus;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/** A "prepared by client" request: what the audit team needs from the client, by when, and its review. */
class PbcItem extends Model
{
    use BelongsToFirm;

    protected $fillable = ['engagement_id', 'reference', 'title', 'description', 'category', 'procedure_id', 'assigned_contact_id', 'due_on', 'requested_by', 'source'];

    protected function casts(): array
    {
        return [
            'category' => PbcCategory::class,
            'status' => PbcStatus::class,
            'due_on' => 'date',
            'requested_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PbcItem $p) {
            $p->uuid ??= (string) Str::uuid();
            $p->status ??= PbcStatus::Draft;
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

    public function contact(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_contact_id');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'pbc_item_documents')->withPivot('linked_by', 'created_at');
    }

    /** Items the client can see (never drafts or cancelled requests). */
    public function scopeVisibleToClient(Builder $q): void
    {
        $q->whereIn('status', [PbcStatus::Requested->value, PbcStatus::Submitted->value, PbcStatus::Accepted->value, PbcStatus::Returned->value]);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [PbcStatus::Requested, PbcStatus::Returned], true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_on && $this->due_on->isPast() && ! $this->due_on->isToday();
    }
}
