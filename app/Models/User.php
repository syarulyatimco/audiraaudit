<?php

namespace App\Models;

use App\Enums\FirmRole;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Support\Authorization\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * Users are NOT globally tenant-scoped: authentication must resolve a user before the tenant
 * is known. Every listing of users must go through $firm->users() (see docs/SECURITY.md).
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'firm_id', 'client_id', 'type', 'firm_role', 'is_firm_admin', 'name', 'email', 'password', 'status',
    ];

    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'];

    protected function casts(): array
    {
        return [
            'type' => UserType::class,
            'firm_role' => FirmRole::class,
            'status' => UserStatus::class,
            'is_firm_admin' => 'boolean',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'mfa_enabled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->uuid ??= (string) Str::uuid();
            $user->email = Str::lower(trim($user->email));
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function engagementMemberships(): HasMany
    {
        return $this->hasMany(EngagementMember::class);
    }

    public function isSuperadmin(): bool
    {
        return $this->type === UserType::Platform;
    }

    public function isClientUser(): bool
    {
        return $this->type === UserType::Client;
    }

    public function client(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isFirmUser(): bool
    {
        return $this->type === UserType::Firm;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function hasPermission(string $permission): bool
    {
        return Permissions::allows($this, $permission);
    }

    public function hasMfaEnabled(): bool
    {
        $raw = $this->getAttributes();

        return ($raw['mfa_enabled_at'] ?? null) !== null && ($raw['mfa_secret'] ?? null) !== null;
    }

    /** MFA is mandatory for platform admins, firm admins and partners (config audira.mfa). */
    public function requiresMfa(): bool
    {
        if (! config('audira.mfa.enforced')) {
            return false;
        }
        $roles = $this->isSuperadmin() ? ['superadmin'] : Permissions::rolesOf($this);

        return count(array_intersect($roles, config('audira.mfa.required_for'))) > 0;
    }

    public function roleLabel(): string
    {
        if ($this->isSuperadmin()) {
            return 'Superadmin';
        }

        return trim(($this->firm_role?->label() ?? 'Client user').($this->is_firm_admin ? ' · Firm admin' : ''));
    }

    public function initials(): string
    {
        return Str::upper(collect(explode(' ', $this->name))->filter()->take(2)->map(fn ($p) => Str::substr($p, 0, 1))->join(''));
    }
}
