<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A saved journal entry test: the criteria used, the entries selected and their results. */
class JetTest extends Model
{
    use BelongsToFirm;

    protected $attributes = ['status' => 'open'];

    protected $fillable = ['engagement_id', 'gl_import_id', 'reference', 'title', 'criteria', 'created_by'];

    protected function casts(): array
    {
        return ['criteria' => 'array', 'concluded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (JetTest $t) => $t->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(JetTestItem::class);
    }

    public function concluder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concluded_by');
    }
}
