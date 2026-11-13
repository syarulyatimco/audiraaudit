<?php

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\AcceptanceRisk;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\ClientStatus;
use App\Enums\EntityType;
use App\Enums\ReportingFramework;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Client extends Model implements Approvable
{
    use BelongsToFirm, HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'registration_no', 'entity_type', 'reporting_framework', 'industry', 'fye_month', 'fye_day',
        'email', 'phone', 'address', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => EntityType::class,
            'reporting_framework' => ReportingFramework::class,
            'status' => ClientStatus::class,
            'acceptance_risk' => AcceptanceRisk::class,
            'independence_confirmed' => 'boolean',
            'accepted_at' => 'datetime',
            'fye_month' => 'integer',
            'fye_day' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Client $client) {
            $client->uuid ??= (string) Str::uuid();
            $client->status ??= ClientStatus::Prospect;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class)->orderByDesc('is_primary')->orderBy('name');
    }

    public function engagements(): HasMany
    {
        return $this->hasMany(Engagement::class);
    }

    public function portalUsers(): HasMany
    {
        return $this->hasMany(User::class)->where('type', 'client');
    }

    public function portalInvitations(): HasMany
    {
        return $this->hasMany(PortalInvitation::class)->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable')->latest('requested_at');
    }

    public function isAccepted(): bool
    {
        return $this->status === ClientStatus::Accepted;
    }

    public function fyeLabel(): string
    {
        return sprintf('%d %s', $this->fye_day, date('F', mktime(0, 0, 0, $this->fye_month, 1)));
    }

    // --- Approvable -------------------------------------------------------------------------

    public function approvalTitle(): string
    {
        return $this->name;
    }

    public function approvalUrl(): string
    {
        return route('clients.show', $this);
    }

    public function approvalEngagement(): ?Engagement
    {
        return null;
    }

    public function onApprovalDecided(Approval $approval): void
    {
        if ($approval->type !== ApprovalType::ClientAcceptance) {
            return;
        }

        if ($approval->status === ApprovalStatus::Approved) {
            $this->status = ClientStatus::Accepted;
            $this->accepted_at = now();
        } elseif ($approval->status === ApprovalStatus::Rejected) {
            $this->status = ClientStatus::Declined;
        } else {
            $this->status = ClientStatus::Prospect;
        }
        $this->save();
    }
}
