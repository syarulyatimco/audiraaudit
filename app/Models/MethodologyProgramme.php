<?php

namespace App\Models;

use App\Enums\FsliClassification;
use App\Enums\ProgrammeStage;
use App\Support\Tenancy\SharedWithFirms;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A library programme for one stage, routed to the engagement, to FSLI codes, or to a classification. */
class MethodologyProgramme extends Model
{
    use SharedWithFirms;

    protected $fillable = ['library_id', 'stage', 'scope', 'fsli_codes', 'fsli_classification', 'ref_prefix', 'title', 'objective', 'sort_order'];

    protected function casts(): array
    {
        return ['stage' => ProgrammeStage::class, 'fsli_codes' => 'array', 'fsli_classification' => FsliClassification::class];
    }

    protected static function booted(): void
    {
        static::creating(fn (MethodologyProgramme $p) => $p->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(MethodologyLibrary::class, 'library_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(MethodologyStep::class, 'programme_id')->orderBy('position');
    }

    public function methodologySteps(): HasMany
    {
        return $this->hasMany(MethodologyStep::class, 'programme_id');
    }

    public function routing(): string
    {
        return match ($this->scope) {
            'engagement' => 'Whole engagement',
            'fsli' => 'FSLI '.implode(', ', $this->fsli_codes ?? []),
            default => 'Any '.strtolower($this->fsli_classification?->label() ?? '').' FSLI without its own programme',
        };
    }
}
