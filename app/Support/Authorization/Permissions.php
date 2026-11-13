<?php

namespace App\Support\Authorization;

use App\Enums\ApprovalType;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Engagement;
use App\Models\User;

/**
 * Single source of truth for role → permission checks (matrix lives in config/audira.php).
 */
class Permissions
{
    /** @return list<string> */
    public static function rolesOf(User $user): array
    {
        if ($user->type !== UserType::Firm || $user->status !== UserStatus::Active) {
            return [];
        }

        $roles = $user->firm_role ? [$user->firm_role->value] : [];
        if ($user->is_firm_admin) {
            $roles[] = 'firm_admin';
        }

        return $roles;
    }

    public static function allows(User $user, string $permission): bool
    {
        $allowed = config('audira.permissions')[$permission] ?? null;
        if ($allowed === null) {
            return false; // unknown permission: deny
        }

        return count(array_intersect(self::rolesOf($user), $allowed)) > 0;
    }

    public static function allowsOnEngagement(User $user, Engagement $engagement, string $permission): bool
    {
        if ($user->firm_id === null || $user->firm_id !== $engagement->firm_id || self::rolesOf($user) === []) {
            return false;
        }

        $allowed = config('audira.engagement_permissions')[$permission] ?? [];
        // Always query membership: a cached relation could be stale after team changes.
        $role = $engagement->members()->where('user_id', $user->id)->first()?->role?->value;

        if ($role !== null && in_array($role, $allowed, true)) {
            return true;
        }

        // Firm-wide supervisors (partners / firm admins) may view every engagement in their firm.
        return $permission === 'engagement.view' && self::allows($user, 'engagements.view_all');
    }

    public static function canDecide(User $user, ApprovalType $type): bool
    {
        $allowed = config('audira.approval_deciders')[$type->value] ?? [];

        return count(array_intersect(self::rolesOf($user), $allowed)) > 0;
    }
}
