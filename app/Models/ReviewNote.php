<?php

namespace App\Models;

use App\Enums\ReviewNoteStatus;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ReviewNote extends Model
{
    use BelongsToFirm;

    protected $fillable = ['working_paper_id', 'raised_by', 'body'];

    protected function casts(): array
    {
        return ['status' => ReviewNoteStatus::class, 'responded_at' => 'datetime', 'cleared_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (ReviewNote $n) {
            $n->uuid ??= (string) Str::uuid();
            $n->status ??= ReviewNoteStatus::Open;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function workingPaper(): BelongsTo
    {
        return $this->belongsTo(WorkingPaper::class);
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function clearer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }
}
