<?php

namespace App\Http\Controllers\Users;

use App\Enums\FirmRole;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Users\InvitationService;
use App\Services\Users\UserManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request, BillingService $billing): View
    {
        Gate::authorize('users.view');
        $firm = $request->user()->firm;

        return view('users.index', [
            'users' => $firm->staff()->orderBy('status')->orderBy('name')->get(),
            'invitations' => Invitation::pending()->with('inviter')->latest()->get(),
            'estimate' => $billing->estimate($firm),
            'seatAvailable' => $billing->hasSeatAvailable($firm),
            'canManage' => $request->user()->hasPermission('users.manage'),
        ]);
    }

    public function invite(Request $request, InvitationService $service): RedirectResponse
    {
        Gate::authorize('users.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'firm_role' => ['required', Rule::enum(FirmRole::class)],
            'is_firm_admin' => ['boolean'],
        ]);

        [, $token] = $service->invite($request->user()->firm, $request->user(), $data['email'], $data['name'],
            FirmRole::from($data['firm_role']), $request->boolean('is_firm_admin'));

        return back()->with('status', "Invitation sent to {$data['email']}.")
            ->with('invitation_link', route('invitations.show', $token));
    }

    public function revokeInvitation(Request $request, Invitation $invitation, InvitationService $service): RedirectResponse
    {
        Gate::authorize('users.manage');
        abort_unless($invitation->isPending(), 422, 'Invitation is no longer pending.');
        $service->revoke($invitation, $request->user());

        return back()->with('status', 'Invitation revoked.');
    }

    public function updateRole(Request $request, User $user, UserManagementService $service): RedirectResponse
    {
        Gate::authorize('users.manage');
        $this->ensureSameFirm($request, $user);

        $data = $request->validate([
            'firm_role' => ['required', Rule::enum(FirmRole::class)],
            'is_firm_admin' => ['boolean'],
        ]);
        $service->updateRole($user, FirmRole::from($data['firm_role']), $request->boolean('is_firm_admin'), $request->user());

        return back()->with('status', "{$user->name}'s role updated.");
    }

    public function deactivate(Request $request, User $user, UserManagementService $service): RedirectResponse
    {
        Gate::authorize('users.manage');
        $this->ensureSameFirm($request, $user);
        $service->deactivate($user, $request->user());

        return back()->with('status', "{$user->name} has been deactivated.");
    }

    public function reactivate(Request $request, User $user, UserManagementService $service): RedirectResponse
    {
        Gate::authorize('users.manage');
        $this->ensureSameFirm($request, $user);
        $service->reactivate($user, $request->user());

        return back()->with('status', "{$user->name} has been reactivated.");
    }

    /** Users are not globally scoped (auth needs them); enforce tenancy explicitly. 404, not 403. */
    private function ensureSameFirm(Request $request, User $user): void
    {
        abort_unless($user->isFirmUser() && $user->firm_id === $request->user()->firm_id, 404);
    }
}
