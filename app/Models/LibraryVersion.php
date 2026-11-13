<?php

namespace App\Models;

use App\Enums\LibraryVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LibraryVersion extends Model
{
    protected $fillable = ['standard_id', 'version_label', 'effective_date', 'source_title', 'source_url', 'change_summary', 'created_by'];

    protected function casts(): array
    {
        return [
            'status' => LibraryVersionStatus::class,
            'effective_date' => 'date',
            'published_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LibraryVersion $v) {
            $v->uuid ??= (string) Str::uuid();
            $v->status ??= LibraryVersionStatus::Draft;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function standard(): BelongsTo
    {
        return $this->belongsTo(LibraryStandard::class, 'standard_id');
    }

    public function paragraphs(): HasMany
    {
        return $this->hasMany(LibraryParagraph::class, 'version_id')->orderBy('sort_order')->orderBy('id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isDraft(): bool
    {
        return $this->status === LibraryVersionStatus::Draft;
    }
}
