<?php

namespace App\Services\Users;

use App\Enums\FirmRole;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Firm;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\StaffInvitation;
use App\Services\Billing\BillingService;
use App\Support\Audit\ActivityLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvitationService
{
    public function __construct(
        private BillingService $billing,
        private ActivityLogger $logger,
        private TenantContext $tenant,
    ) {}

    /**
     * @return array{0: Invitation, 1: string} the invitation and the one-time plain token
     */
    public function invite(Firm $firm, User $inviter, string $email, string $name, FirmRole $role, bool $isAdmin): array
    {
        $email = Str::lower(trim($email));

        if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'A user with this email already exists.']);
        }
        if (Invitation::where('email', $email)->pending()->exists()) {
            throw ValidationException::withMessages(['email' => 'There is already a pending invitation for this email.']);
        }
        if (! $this->billing->hasSeatAvailable($firm)) {
            throw ValidationException::withMessages(['email' => 'Your plan has no seats available. Upgrade your plan or deactivate a user.']);
        }

        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($firm, $inviter, $email, $name, $role, $isAdmin, $token) {
            $invitation = Invitation::create([
                'firm_id' => $firm->id,
                'email' => $email,
                'name' => $name,
                'firm_role' => $role,
                'is_firm_admin' => $isAdmin,
                'token_hash' => hash('sha256', $token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(config('audira.invitation_ttl_days')),
            ]);
            $this->logger->log('user.invited', "Invited {$email} as {$role->label()}", $invitation, ['email' => $email, 'role' => $role->value, 'firm_admin' => $isAdmin], $inviter);

            return $invitation;
        });

        Notification::route('mail', $email)->notify(new StaffInvitation($firm->name, $inviter->name, $token));

        return [$invitation, $token];
    }

    /** Resolve a pending invitation by its plain token (system mode: no tenant yet). */
    public function findPending(string $token): ?Invitation
    {
        return $this->tenant->runAsSystem(fn () => Invitation::with('firm')
            ->where('token_hash', hash('sha256', $token))
            ->pending()
            ->first());
    }

    public function accept(string $token, string $name, string $password): User
    {
        $invitation = $this->findPending($token);
        if (! $invitation) {
            throw ValidationException::withMessages(['token' => 'This invitation is invalid or has expired.']);
        }

        return $this->tenant->runForFirm($invitation->firm_id, fn () => DB::transaction(function () use ($invitation, $name, $password) {
            if (User::whereRaw('lower(email) = ?', [$invitation->email])->exists()) {
                throw ValidationException::withMessages(['token' => 'An account with this email already exists.']);
            }

            $user = User::create([
                'firm_id' => $invitation->firm_id,
                'type' => UserType::Firm,
                'firm_role' => $invitation->firm_role,
                'is_firm_admin' => $invitation->is_firm_admin,
                'name' => $name,
                'email' => $invitation->email,
                'password' => $password,
                'status' => UserStatus::Active,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $invitation->accepted_at = now();
            $invitation->save();

            $this->logger->log('user.joined', "{$user->name} accepted the invitation", $user, [], $user, $invitation->firm_id);

            return $user;
        }));
    }

    public function revoke(Invitation $invitation, User $actor): void
    {
        DB::transaction(function () use ($invitation, $actor) {
            $invitation->revoked_at = now();
            $invitation->save();
            $this->logger->log('user.invitation_revoked', "Revoked invitation for {$invitation->email}", $invitation, [], $actor);
        });
    }
}
