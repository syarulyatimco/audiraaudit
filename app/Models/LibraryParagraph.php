<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable once its version is published (enforced by a DB trigger). */
class LibraryParagraph extends Model
{
    protected $fillable = ['version_id', 'paragraph_ref', 'heading', 'body', 'sort_order'];

    protected $hidden = ['search'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(LibraryVersion::class, 'version_id');
    }

    /** Full-text search (PostgreSQL tsvector), ranked. */
    public function scopeSearch(Builder $query, string $term): void
    {
        $query->whereRaw("search @@ websearch_to_tsquery('english', ?)", [$term])
            ->orderByRaw("ts_rank(search, websearch_to_tsquery('english', ?)) desc", [$term]);
    }

    /** e.g. "ISA 320 ¶10 (2024 edition)" */
    public function citation(): string
    {
        $v = $this->version;

        return $v->standard->reference.' ¶'.$this->paragraph_ref.' ('.$v->version_label.')';
    }
}
