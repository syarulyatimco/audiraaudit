<?php

namespace App\Models;

use App\Enums\ProgrammeStage;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** An engagement's programme grid for one stage, attached to an FSLI (or to the engagement for planning). */
class ProgrammeSheet extends Model
{
    use BelongsToFirm;

    protected $fillable = ['engagement_id', 'engagement_fsli_id', 'stage', 'reference', 'title', 'objective', 'source_programme_id', 'columns', 'sort_order', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['stage' => ProgrammeStage::class, 'columns' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(fn (ProgrammeSheet $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function fsli(): BelongsTo
    {
        return $this->belongsTo(EngagementFsli::class, 'engagement_fsli_id');
    }

    public function sourceProgramme(): BelongsTo
    {
        return $this->belongsTo(MethodologyProgramme::class, 'source_programme_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ProgrammeRow::class, 'sheet_id')->orderBy('position');
    }

    /** Alias used by scoped route bindings. */
    public function programmeRows(): HasMany
    {
        return $this->hasMany(ProgrammeRow::class, 'sheet_id');
    }

    /** @return list<array{key: string, label: string}> */
    public function customColumns(): array
    {
        return array_values($this->columns ?? []);
    }
}
