<?php

namespace App\Services\Portal;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Client;
use App\Models\PortalInvitation;
use App\Models\User;
use App\Notifications\PortalInvitationNotification;
use App\Support\Audit\ActivityLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Client portal access: free client users, scoped to one client (CLAUDE.md §3). */
class PortalService
{
    public function __construct(private TenantContext $tenant, private ActivityLogger $logger) {}

    /** @return array{0: PortalInvitation, 1: string} */
    public function invite(Client $client, string $name, string $email, User $inviter, ?int $contactId = null): array
    {
        if (! $client->isAccepted()) {
            throw ValidationException::withMessages(['email' => 'Portal access is available once the client is accepted.']);
        }
        $email = mb_strtolower(trim($email));
        if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'This email already has an AUDIRA account.']);
        }
        if ($client->portalInvitations()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
            throw ValidationException::withMessages(['email' => 'An invitation is already pending for this email.']);
        }
        $token = Str::random(48);
        $inv = DB::transaction(function () use ($client, $name, $email, $inviter, $contactId, $token) {
            $inv = $client->portalInvitations()->create([
                'client_contact_id' => $contactId, 'name' => $name, 'email' => $email, 'token_hash' => hash('sha256', $token),
                'invited_by' => $inviter->id, 'expires_at' => now()->addDays(config('audira.invitation_ttl_days')),
            ]);
            $this->logger->log('portal.invited', "{$email} invited to the client portal for {$client->name}", $inv, [], $inviter);

            return $inv;
        });
        Notification::route('mail', $email)->notify(new PortalInvitationNotification($inviter->firm->name, $client->name, $token));

        return [$inv, $token];
    }

    public function findPending(string $token): ?PortalInvitation
    {
        return $this->tenant->runAsSystem(fn () => PortalInvitation::with('client')->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->first());
    }

    public function accept(string $token, string $password): User
    {
        $inv = $this->findPending($token) ?? throw ValidationException::withMessages(['token' => 'This invitation is invalid or has expired.']);

        return $this->tenant->runForFirm($inv->firm_id, fn () => DB::transaction(function () use ($inv, $password) {
            if (User::whereRaw('lower(email) = ?', [$inv->email])->exists()) {
                throw ValidationException::withMessages(['token' => 'An account with this email already exists.']);
            }
            $user = User::create([
                'firm_id' => $inv->firm_id, 'client_id' => $inv->client_id, 'type' => UserType::Client,
                'name' => $inv->name, 'email' => $inv->email, 'password' => $password, 'status' => UserStatus::Active,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $inv->forceFill(['accepted_at' => now()])->save();
            $this->logger->log('portal.joined', "{$user->name} activated client portal access", $user, ['client_id' => $inv->client_id], $user, $inv->firm_id);

            return $user;
        }));
    }

    public function revoke(PortalInvitation $inv, User $actor): void
    {
        $inv->forceFill(['revoked_at' => now()])->save();
        $this->logger->log('portal.invitation_revoked', "Portal invitation for {$inv->email} revoked", $inv, [], $actor);
    }

    public function setActive(User $clientUser, bool $active, User $actor): void
    {
        if (! $clientUser->isClientUser() || $clientUser->firm_id !== $actor->firm_id) {
            abort(404);
        }
        $clientUser->forceFill(['status' => $active ? UserStatus::Active : UserStatus::Deactivated])->save();
        $this->logger->log($active ? 'portal.user_reactivated' : 'portal.user_deactivated', ($active ? 'Reactivated' : 'Deactivated')." portal user {$clientUser->email}", $clientUser, [], $actor);
    }
}
