<?php

namespace App\Models;

use App\Enums\WorkingPaperSection;
use App\Enums\WorkingPaperStatus;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WorkingPaper extends Model
{
    use BelongsToFirm;

    public const ENTITY_UNDERSTANDING = 'entity_understanding';

    public const AUDIT_STRATEGY = 'audit_strategy';

    public const RISK_ASSESSMENT = 'risk_assessment';

    public const MATERIALITY = 'materiality';

    public const AUDIT_PROGRAMME = 'audit_programme';

    /** Planning papers whose content is the planning module's data and whose sign-off has its own gates. */
    public const PLANNING_KEYS = [self::AUDIT_STRATEGY, self::RISK_ASSESSMENT, self::MATERIALITY, self::AUDIT_PROGRAMME];

    protected $fillable = ['engagement_id', 'reference', 'title', 'section', 'system_key', 'objective', 'work_performed', 'conclusion', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['section' => WorkingPaperSection::class, 'status' => WorkingPaperStatus::class];
    }

    protected static function booted(): void
    {
        static::creating(function (WorkingPaper $wp) {
            $wp->uuid ??= (string) Str::uuid();
            $wp->status ??= WorkingPaperStatus::Draft;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function signoffs(): HasMany
    {
        return $this->hasMany(WpSignoff::class)->orderBy('signed_at');
    }

    public function activeSignoffs(): HasMany
    {
        return $this->hasMany(WpSignoff::class)->whereNull('invalidated_at');
    }

    public function reviewNotes(): HasMany
    {
        return $this->hasMany(ReviewNote::class)->orderBy('created_at');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'working_paper_documents')->withPivot('linked_by', 'created_at');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(WorkingPaperCitation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPlanningPaper(): bool
    {
        return in_array($this->system_key, self::PLANNING_KEYS, true);
    }

    public function isEntityUnderstanding(): bool
    {
        return $this->system_key === self::ENTITY_UNDERSTANDING;
    }

    public function openNotesCount(): int
    {
        return $this->reviewNotes()->where('status', '!=', 'cleared')->count();
    }
}
