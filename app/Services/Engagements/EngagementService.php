<?php

namespace App\Services\Engagements;

use App\Enums\EngagementRole;
use App\Enums\EngagementStatus;
use App\Enums\FirmRole;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Client;
use App\Models\Engagement;
use App\Models\EngagementMember;
use App\Models\User;
use App\Support\Audit\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EngagementService
{
    public function __construct(private ActivityLogger $logger) {}

    /**
     * @param  array<string, mixed>  $data  validated engagement fields + engagement_partner_id, manager_id?
     */
    public function create(Client $client, array $data, User $actor): Engagement
    {
        if (! $client->isAccepted()) {
            throw ValidationException::withMessages(['client_id' => 'Engagements can only be created for clients whose acceptance has been approved.']);
        }

        $partner = $this->staffMember($actor, (int) $data['engagement_partner_id']);
        $this->assertRoleFits($partner, EngagementRole::EngagementPartner);

        return DB::transaction(function () use ($client, $data, $actor, $partner) {
            $engagement = $client->engagements()->create([
                'code' => $data['code'] ?? $this->nextCode($client, $data['period_end']),
                'name' => $data['name'],
                'engagement_type' => $data['engagement_type'],
                'reporting_framework' => $data['reporting_framework'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'reporting_deadline' => $data['reporting_deadline'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->attach($engagement, $partner, EngagementRole::EngagementPartner, $actor);

            if (! empty($data['manager_id'])) {
                $manager = $this->staffMember($actor, (int) $data['manager_id']);
                if (! $manager->is($partner)) {
                    $this->assertRoleFits($manager, EngagementRole::Manager);
                    $this->attach($engagement, $manager, EngagementRole::Manager, $actor);
                }
            }

            // The creator is always on the team so they keep access to what they created.
            if (! $engagement->members()->where('user_id', $actor->id)->exists()) {
                $this->attach($engagement, $actor, $actor->firm_role === FirmRole::Partner || $actor->firm_role === FirmRole::Manager
                    ? EngagementRole::Manager : EngagementRole::Senior, $actor);
            }

            $this->logger->log('engagement.created', "Engagement {$engagement->code} created for {$client->name}", $engagement, [], $actor);

            return $engagement->load('members.user');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Engagement $engagement, array $data, User $actor): Engagement
    {
        $this->assertNotLocked($engagement);

        return DB::transaction(function () use ($engagement, $data, $actor) {
            $engagement->fill($data);
            $changed = array_keys($engagement->getDirty());
            $engagement->save();
            if ($changed) {
                $this->logger->log('engagement.updated', "Engagement {$engagement->code} updated", $engagement, ['fields' => $changed], $actor);
            }

            return $engagement;
        });
    }

    public function addMember(Engagement $engagement, User $user, EngagementRole $role, User $actor): EngagementMember
    {
        $this->assertNotLocked($engagement);
        $user = $this->staffMember($actor, $user->id);
        $this->assertRoleFits($user, $role);

        if ($engagement->members()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['user_id' => "{$user->name} is already on this engagement."]);
        }
        if (in_array($role, [EngagementRole::EngagementPartner, EngagementRole::EqrReviewer], true)
            && $engagement->members()->where('role', $role->value)->exists()) {
            throw ValidationException::withMessages(['role' => "This engagement already has an {$role->label()}."]);
        }

        return DB::transaction(function () use ($engagement, $user, $role, $actor) {
            $member = $this->attach($engagement, $user, $role, $actor);
            $this->logger->log('engagement.member_added', "{$user->name} added as {$role->label()}", $engagement, ['user_id' => $user->id, 'role' => $role->value], $actor);

            return $member;
        });
    }

    public function removeMember(EngagementMember $member, User $actor): void
    {
        $engagement = $member->engagement;
        $this->assertNotLocked($engagement);

        if ($member->role === EngagementRole::EngagementPartner) {
            throw ValidationException::withMessages(['member' => 'The engagement partner cannot be removed. Assign a replacement partner first.']);
        }
        if ($member->user_id === $actor->id) {
            throw ValidationException::withMessages(['member' => 'You cannot remove yourself from the engagement.']);
        }

        DB::transaction(function () use ($member, $engagement, $actor) {
            $name = $member->user->name;
            $member->delete();
            $this->logger->log('engagement.member_removed', "{$name} removed from engagement", $engagement, ['user_id' => $member->user_id], $actor);
        });
    }

    public function replacePartner(Engagement $engagement, User $newPartner, User $actor): void
    {
        $this->assertNotLocked($engagement);
        $newPartner = $this->staffMember($actor, $newPartner->id);
        $this->assertRoleFits($newPartner, EngagementRole::EngagementPartner);

        DB::transaction(function () use ($engagement, $newPartner, $actor) {
            $engagement->members()->where('role', EngagementRole::EngagementPartner->value)->delete();
            $engagement->members()->where('user_id', $newPartner->id)->delete();
            $this->attach($engagement, $newPartner, EngagementRole::EngagementPartner, $actor);
            $this->logger->log('engagement.partner_changed', "{$newPartner->name} is now engagement partner", $engagement, ['user_id' => $newPartner->id], $actor);
        });
    }

    public function changeStatus(Engagement $engagement, EngagementStatus $target, User $actor): void
    {
        if ($target === EngagementStatus::SignedOff || $target === EngagementStatus::Archived) {
            throw ValidationException::withMessages(['status' => 'Sign-off requires the audit report sign-off approval (available from the Reporting module).']);
        }
        if (! $engagement->status->canTransitionTo($target)) {
            throw ValidationException::withMessages(['status' => "Cannot move from {$engagement->status->label()} to {$target->label()}."]);
        }

        DB::transaction(function () use ($engagement, $target, $actor) {
            $from = $engagement->status;
            $engagement->status = $target;
            $engagement->save();
            $this->logger->log('engagement.status_changed', "Status changed from {$from->label()} to {$target->label()}", $engagement, ['from' => $from->value, 'to' => $target->value], $actor);
        });
    }

    private function attach(Engagement $engagement, User $user, EngagementRole $role, User $actor): EngagementMember
    {
        return $engagement->members()->create(['user_id' => $user->id, 'role' => $role, 'added_by' => $actor->id]);
    }

    /** Resolve a user only from the actor's own firm, active staff only. */
    private function staffMember(User $actor, int $userId): User
    {
        $user = User::where('firm_id', $actor->firm_id)->where('type', UserType::Firm->value)
            ->where('status', UserStatus::Active->value)->find($userId);

        if (! $user) {
            throw ValidationException::withMessages(['user_id' => 'Selected user is not an active member of your firm.']);
        }

        return $user;
    }

    private function assertRoleFits(User $user, EngagementRole $role): void
    {
        $ok = match ($role) {
            EngagementRole::EngagementPartner, EngagementRole::EqrReviewer => $user->firm_role === FirmRole::Partner,
            EngagementRole::Manager => in_array($user->firm_role, [FirmRole::Partner, FirmRole::Manager], true),
            EngagementRole::Senior, EngagementRole::Staff => $user->firm_role !== FirmRole::Administrator,
        };

        if (! $ok) {
            throw ValidationException::withMessages(['role' => "{$user->name} ({$user->firm_role?->label()}) cannot act as {$role->label()}."]);
        }
    }

    private function assertNotLocked(Engagement $engagement): void
    {
        if ($engagement->status->isLocked()) {
            throw ValidationException::withMessages(['engagement' => 'This engagement is signed off and locked.']);
        }
    }

    private function nextCode(Client $client, string $periodEnd): string
    {
        $year = substr($periodEnd, 0, 4);
        $prefix = 'ENG-'.$year.'-';
        $last = Engagement::withTrashed()->where('code', 'like', $prefix.'%')->orderByDesc('code')->value('code');
        $n = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
