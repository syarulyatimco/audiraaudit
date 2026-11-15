<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GlImport extends Model
{
    use BelongsToFirm;

    protected $attributes = ['status' => 'pending'];

    protected $fillable = ['engagement_id', 'document_id', 'header_row', 'column_map', 'preview', 'imported_by'];

    protected function casts(): array
    {
        return ['column_map' => 'array', 'preview' => 'array', 'reconciliation' => 'array', 'first_date' => 'date', 'last_date' => 'date', 'activated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (GlImport $i) => $i->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GlLine::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
