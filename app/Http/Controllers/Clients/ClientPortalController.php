<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PortalInvitation;
use App\Models\User;
use App\Services\Portal\PortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientPortalController extends Controller
{
    public function invite(Request $request, Client $client, PortalService $portal): RedirectResponse
    {
        Gate::authorize('clients.manage_portal');
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:190'], 'client_contact_id' => ['nullable', 'integer']]);
        $contact = $data['client_contact_id'] ?? null;
        abort_if($contact && ! $client->contacts()->whereKey($contact)->exists(), 404);
        $portal->invite($client, $data['name'], $data['email'], $request->user(), $contact);

        return back()->with('status', "Portal invitation sent to {$data['email']}.");
    }

    public function revoke(Request $request, Client $client, PortalInvitation $invitation, PortalService $portal): RedirectResponse
    {
        Gate::authorize('clients.manage_portal');
        abort_unless($invitation->client_id === $client->id, 404);
        $portal->revoke($invitation, $request->user());

        return back()->with('status', 'Invitation revoked.');
    }

    public function toggle(Request $request, Client $client, User $user, PortalService $portal): RedirectResponse
    {
        Gate::authorize('clients.manage_portal');
        abort_unless($user->client_id === $client->id, 404);
        $portal->setActive($user, ! $user->isActive(), $request->user());

        return back()->with('status', $user->fresh()->isActive() ? 'Portal access restored.' : 'Portal access removed.');
    }
}
