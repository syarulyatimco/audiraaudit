<?php

namespace App\Models;

use App\Enums\EngagementRole;
use App\Enums\EngagementStatus;
use App\Enums\EngagementType;
use App\Enums\ReportingFramework;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Engagement extends Model
{
    use BelongsToFirm, HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id', 'code', 'name', 'engagement_type', 'reporting_framework', 'period_start', 'period_end',
        'reporting_deadline', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'engagement_type' => EngagementType::class,
            'reporting_framework' => ReportingFramework::class,
            'status' => EngagementStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'reporting_deadline' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Engagement $engagement) {
            $engagement->uuid ??= (string) Str::uuid();
            $engagement->status ??= EngagementStatus::Planning;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(EngagementMember::class);
    }

    public function workingPapers(): HasMany
    {
        return $this->hasMany(WorkingPaper::class)->orderBy('reference');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function fslis(): HasMany
    {
        return $this->hasMany(EngagementFsli::class)->orderBy('sort_order')->orderBy('code');
    }

    public function tbImports(): HasMany
    {
        return $this->hasMany(TbImport::class);
    }

    public function accountMappings(): HasMany
    {
        return $this->hasMany(AccountMapping::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(Adjustment::class);
    }

    public function materialityAssessments(): HasMany
    {
        return $this->hasMany(MaterialityAssessment::class)->orderByDesc('version');
    }

    /** Alias used by scoped route binding of {materiality}. */
    public function materialities(): HasMany
    {
        return $this->hasMany(MaterialityAssessment::class);
    }

    /** The approved materiality currently in force, if any. */
    public function currentMateriality(): ?MaterialityAssessment
    {
        return $this->materialityAssessments()->where('status', 'approved')->first();
    }

    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class)->orderBy('reference');
    }

    public function auditStrategy(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(AuditStrategy::class);
    }

    public function procedures(): HasMany
    {
        return $this->hasMany(Procedure::class)->orderBy('reference');
    }

    public function completionItems(): HasMany
    {
        return $this->hasMany(CompletionItem::class)->orderBy('id');
    }

    public function completion(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EngagementCompletion::class);
    }

    public function auditReport(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(AuditReport::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(MonitoringAlert::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class)->orderBy('reference');
    }

    public function samplingPlans(): HasMany
    {
        return $this->hasMany(SamplingPlan::class)->orderBy('reference');
    }

    /** Alias used by scoped route binding of {plan}. */
    public function plans(): HasMany
    {
        return $this->hasMany(SamplingPlan::class);
    }

    public function pbcItems(): HasMany
    {
        return $this->hasMany(PbcItem::class)->orderBy('reference');
    }

    /** Alias used by scoped route binding of {item}. */
    public function items(): HasMany
    {
        return $this->hasMany(PbcItem::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EngagementMessage::class)->latest('id');
    }

    public function aiRuns(): HasMany
    {
        return $this->hasMany(AiRun::class)->latest('id');
    }

    public function understandingSections(): HasMany
    {
        return $this->hasMany(EntityUnderstandingSection::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Engagements the user may see: all (partners/admins) or only those they are staffed on. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (Permissions::allows($user, 'engagements.view_all')) {
            return;
        }

        $query->whereHas('members', fn (Builder $q) => $q->where('user_id', $user->id));
    }

    public function roleOf(User $user): ?EngagementRole
    {
        $members = $this->relationLoaded('members') ? $this->members : $this->members()->get();

        return $members->firstWhere('user_id', $user->id)?->role;
    }

    public function partner(): ?User
    {
        return $this->members->firstWhere('role', EngagementRole::EngagementPartner)?->user;
    }

    public function periodLabel(): string
    {
        return $this->period_start->format('j M Y').' – '.$this->period_end->format('j M Y');
    }
}
