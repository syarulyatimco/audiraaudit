<?php

namespace App\Policies;

use App\Models\Engagement;
use App\Models\User;
use App\Support\Authorization\Permissions;

class EngagementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isFirmUser() && $user->isActive();
    }

    public function view(User $user, Engagement $engagement): bool
    {
        return Permissions::allowsOnEngagement($user, $engagement, 'engagement.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('engagements.create');
    }

    public function update(User $user, Engagement $engagement): bool
    {
        return Permissions::allowsOnEngagement($user, $engagement, 'engagement.update');
    }

    public function manageTeam(User $user, Engagement $engagement): bool
    {
        return Permissions::allowsOnEngagement($user, $engagement, 'engagement.manage_team');
    }

    public function changeStatus(User $user, Engagement $engagement): bool
    {
        return Permissions::allowsOnEngagement($user, $engagement, 'engagement.change_status');
    }
}
