<?php

namespace App\Models;

use App\Enums\ProcedureNature;
use App\Enums\ProcedureStatus;
use App\Enums\ProcedureTiming;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** An audit procedure in the programme, responding to one or more assessed risks (ISA 330). */
class Procedure extends Model
{
    use BelongsToFirm;

    protected $fillable = ['engagement_id', 'reference', 'engagement_fsli_id', 'title', 'steps', 'nature', 'timing', 'assertions', 'assigned_to', 'budget_hours', 'source', 'ai_run_id', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'nature' => ProcedureNature::class,
            'timing' => ProcedureTiming::class,
            'status' => ProcedureStatus::class,
            'assertions' => 'array',
            'budget_hours' => 'float',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Procedure $p) {
            $p->uuid ??= (string) Str::uuid();
            $p->status ??= ProcedureStatus::NotStarted;
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

    public function fsli(): BelongsTo
    {
        return $this->belongsTo(EngagementFsli::class, 'engagement_fsli_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function risks(): BelongsToMany
    {
        return $this->belongsToMany(Risk::class, 'procedure_risks')->orderBy('reference');
    }

    public function workingPaper(): BelongsTo
    {
        return $this->belongsTo(WorkingPaper::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    public function samplingPlans(): HasMany
    {
        return $this->hasMany(SamplingPlan::class);
    }

    public function scopeActive(Builder $q): void
    {
        $q->whereNull('withdrawn_at');
    }

    public function isActive(): bool
    {
        return $this->withdrawn_at === null;
    }
}
