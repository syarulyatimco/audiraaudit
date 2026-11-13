<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Document extends Model
{
    use BelongsToFirm;

    protected $fillable = ['engagement_id', 'title', 'category', 'description', 'uploaded_by'];

    protected function casts(): array
    {
        return ['category' => DocumentCategory::class, 'archived_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (Document $d) => $d->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_no');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function pbcItems(): BelongsToMany
    {
        return $this->belongsToMany(PbcItem::class, 'pbc_item_documents');
    }

    public function extractions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DocumentExtraction::class)->latest('id');
    }

    public function workingPapers(): BelongsToMany
    {
        return $this->belongsToMany(WorkingPaper::class, 'working_paper_documents');
    }

    public function scopeActive(Builder $q): void
    {
        $q->whereNull('archived_at');
    }
}
