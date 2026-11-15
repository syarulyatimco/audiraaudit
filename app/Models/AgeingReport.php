<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** An aged receivables or payables listing as provided by the client. */
class AgeingReport extends Model
{
    use BelongsToFirm;

    protected $attributes = ['status' => 'pending'];

    protected $fillable = ['engagement_id', 'document_id', 'type', 'period', 'engagement_fsli_id', 'as_at', 'header_row', 'column_map', 'buckets', 'preview', 'imported_by'];

    protected function casts(): array
    {
        return ['column_map' => 'array', 'buckets' => 'array', 'preview' => 'array', 'as_at' => 'date', 'activated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (AgeingReport $r) => $r->uuid ??= (string) Str::uuid());
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

    public function fsli(): BelongsTo
    {
        return $this->belongsTo(EngagementFsli::class, 'engagement_fsli_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AgeingLine::class)->orderBy('seq');
    }

    public function label(): string
    {
        return ($this->type === 'receivables' ? 'Aged receivables' : 'Aged payables').' at '.$this->as_at->format('j M Y').($this->period === 'prior' ? ' (prior year)' : '');
    }
}
