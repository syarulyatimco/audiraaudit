<?php

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\ApprovalStatus;
use App\Enums\BenchmarkBasis;
use App\Enums\MaterialityBenchmark;
use App\Enums\MaterialityStage;
use App\Enums\MaterialityStatus;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * One version of the engagement's materiality (overall, performance, clearly trivial and specific).
 * Versions are never edited once approved (DB trigger); a revision creates a new version and the
 * approval of that version supersedes the previous one.
 */
class MaterialityAssessment extends Model implements Approvable
{
    use BelongsToFirm;

    protected $fillable = [
        'engagement_id', 'version', 'stage', 'benchmark', 'benchmark_basis', 'benchmark_amount_sen', 'benchmark_reason',
        'om_percentage', 'om_sen', 'pm_percentage', 'pm_sen', 'ct_percentage', 'ct_sen',
        'rationale', 'pm_rationale', 'ct_rationale', 'revision_reason', 'fs_snapshot', 'source', 'ai_run_id', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'stage' => MaterialityStage::class,
            'status' => MaterialityStatus::class,
            'benchmark' => MaterialityBenchmark::class,
            'benchmark_basis' => BenchmarkBasis::class,
            'benchmark_amount_sen' => 'integer',
            'om_sen' => 'integer', 'pm_sen' => 'integer', 'ct_sen' => 'integer',
            'om_percentage' => 'float', 'pm_percentage' => 'float', 'ct_percentage' => 'float',
            'fs_snapshot' => 'array',
            'approved_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MaterialityAssessment $m) {
            $m->uuid ??= (string) Str::uuid();
            $m->status ??= MaterialityStatus::Draft;
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

    public function specifics(): HasMany
    {
        return $this->hasMany(SpecificMateriality::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable')->latest('requested_at');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [MaterialityStatus::Draft, MaterialityStatus::Rejected], true);
    }

    public function label(): string
    {
        return "Version {$this->version} ({$this->stage->label()})";
    }

    // --- Approvable ---------------------------------------------------------------------------

    public function approvalTitle(): string
    {
        return "Materiality v{$this->version} · {$this->engagement->code}";
    }

    public function approvalUrl(): string
    {
        return route('materiality.index', $this->engagement);
    }

    public function approvalEngagement(): ?Engagement
    {
        return $this->engagement;
    }

    public function onApprovalDecided(Approval $approval): void
    {
        app(\App\Services\Planning\MaterialityService::class)->applyDecision($this, $approval);
    }
}
