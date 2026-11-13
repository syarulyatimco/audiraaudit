<?php

namespace App\Models;

use App\Enums\FirmStatus;
use App\Enums\ReportingFramework;
use App\Enums\SubscriptionStatus;
use App\Enums\UserType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * The tenant. Protected by RLS on its own id (a firm can only read itself).
 */
class Firm extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'registration_no', 'audit_firm_no', 'email', 'phone', 'address_line1', 'address_line2',
        'city', 'state', 'postcode', 'country', 'default_framework', 'status', 'trial_ends_at', 'suspended_reason',
    ];
    // AI settings (ai_*) are deliberately not fillable: changed only via AiSettingsService (firm admin, logged).

    protected function casts(): array
    {
        return [
            'status' => FirmStatus::class,
            'default_framework' => ReportingFramework::class,
            'trial_ends_at' => 'datetime',
            'ai_enabled' => 'boolean',
            'ai_enabled_at' => 'datetime',
            'ai_disabled_agents' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Firm $firm) {
            $firm->uuid ??= (string) Str::uuid();
            if (empty($firm->slug)) {
                $base = Str::slug($firm->name) ?: 'firm';
                $firm->slug = $base.'-'.Str::lower(Str::random(6));
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function staff(): HasMany
    {
        return $this->users()->where('type', UserType::Firm->value);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->latestOfMany();
    }

    public function isSuspended(): bool
    {
        return in_array($this->status, [FirmStatus::Suspended, FirmStatus::Cancelled], true);
    }
}
