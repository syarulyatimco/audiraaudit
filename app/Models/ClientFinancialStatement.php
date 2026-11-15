<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Management's financial statements for an engagement, prepared by the client in the portal. */
class ClientFinancialStatement extends Model
{
    use BelongsToFirm;

    public const STATEMENTS = ['sofp', 'sopl', 'soce', 'cfs'];

    protected $attributes = ['status' => 'draft'];

    protected $fillable = ['engagement_id', 'template', 'statements', 'notes', 'updated_by'];

    protected function casts(): array
    {
        return ['statements' => 'array', 'notes' => 'array', 'tieout' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(fn (ClientFinancialStatement $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ClientFsVersion::class, 'statement_id')->orderByDesc('version');
    }

    public function latestVersion(): ?ClientFsVersion
    {
        return $this->versions()->first();
    }

    public function acceptedVersion(): ?ClientFsVersion
    {
        return $this->status === 'accepted' ? $this->versions()->where('review_status', 'accepted')->first() : null;
    }

    public function label(string $key): string
    {
        return $this->statements[$key]['label'] ?? $key;
    }
}
