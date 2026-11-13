<?php

namespace App\Services\Users;

use App\Enums\FirmRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Support\Audit\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    public function __construct(private ActivityLogger $logger, private BillingService $billing) {}

    public function updateRole(User $user, FirmRole $role, bool $isAdmin, User $actor): User
    {
        if ($user->is_firm_admin && ! $isAdmin) {
            $this->assertNotLastAdmin($user);
        }

        return DB::transaction(function () use ($user, $role, $isAdmin, $actor) {
            $before = ['role' => $user->firm_role?->value, 'firm_admin' => $user->is_firm_admin];
            $user->firm_role = $role;
            $user->is_firm_admin = $isAdmin;
            $user->save();
            $this->logger->log('user.role_changed', "Changed {$user->name}'s role to {$role->label()}".($isAdmin ? ' (firm admin)' : ''), $user, [
                'before' => $before, 'after' => ['role' => $role->value, 'firm_admin' => $isAdmin],
            ], $actor);

            return $user;
        });
    }

    public function deactivate(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => 'You cannot deactivate your own account.']);
        }
        if ($user->is_firm_admin) {
            $this->assertNotLastAdmin($user);
        }

        DB::transaction(function () use ($user, $actor) {
            $user->status = UserStatus::Deactivated;
            $user->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $this->logger->log('user.deactivated', "Deactivated {$user->name}", $user, [], $actor);
        });
    }

    public function reactivate(User $user, User $actor): void
    {
        if (! $this->billing->hasSeatAvailable($user->firm)) {
            throw ValidationException::withMessages(['user' => 'Your plan has no seats available.']);
        }

        DB::transaction(function () use ($user, $actor) {
            $user->status = UserStatus::Active;
            $user->save();
            $this->logger->log('user.reactivated', "Reactivated {$user->name}", $user, [], $actor);
        });
    }

    private function assertNotLastAdmin(User $user): void
    {
        $admins = User::where('firm_id', $user->firm_id)->where('is_firm_admin', true)
            ->where('status', UserStatus::Active->value)->count();

        if ($admins <= 1) {
            throw ValidationException::withMessages(['user' => 'A firm must keep at least one active firm admin.']);
        }
    }
}
