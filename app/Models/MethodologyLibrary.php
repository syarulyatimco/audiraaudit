<?php

namespace App\Models;

use App\Support\Tenancy\SharedWithFirms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** An audit methodology: AUDIRA's template (firm_id NULL) or a firm's own editable copy. */
class MethodologyLibrary extends Model
{
    use SharedWithFirms;

    protected $fillable = ['code', 'name', 'description', 'based_on_id', 'version', 'status', 'created_by', 'updated_by'];

    protected static function booted(): void
    {
        static::creating(fn (MethodologyLibrary $l) => $l->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function programmes(): HasMany
    {
        return $this->hasMany(MethodologyProgramme::class, 'library_id')->orderBy('sort_order')->orderBy('ref_prefix');
    }

    public function methodologyProgrammes(): HasMany
    {
        return $this->programmes();
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(MethodologyLibrary::class, 'based_on_id');
    }

    public function scopeActive(Builder $q): void
    {
        $q->where('status', 'active');
    }

    public function isEditableBy(?User $user): bool
    {
        return ! $this->isPlatform() && $user && $user->firm_id === $this->firm_id && $this->status === 'active';
    }
}
