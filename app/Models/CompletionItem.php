<?php

namespace App\Models;

use App\Enums\CompletionItemStatus;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CompletionItem extends Model
{
    use BelongsToFirm;

    protected $fillable = ['engagement_id', 'key', 'section', 'title'];

    protected function casts(): array
    {
        return ['status' => CompletionItemStatus::class, 'completed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (CompletionItem $i) {
            $i->uuid ??= (string) Str::uuid();
            $i->status ??= CompletionItemStatus::Open;
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

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function guidance(): ?string
    {
        return config("audira.completion.items.{$this->key}.1");
    }
}
