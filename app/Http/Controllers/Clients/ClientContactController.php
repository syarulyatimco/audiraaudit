<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientContact;
use App\Services\Clients\ClientService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientContactController extends Controller
{
    public function store(Request $request, Client $client, ClientService $service): RedirectResponse
    {
        Gate::authorize('update', $client);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'position' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_primary' => ['boolean'],
        ]);
        $data['is_primary'] = $request->boolean('is_primary');

        $service->addContact($client, $data, $request->user());

        return back()->with('status', 'Contact added.');
    }

    public function destroy(Request $request, Client $client, ClientContact $contact, ClientService $service): RedirectResponse
    {
        Gate::authorize('update', $client);
        $service->removeContact($contact, $request->user());

        return back()->with('status', 'Contact removed.');
    }
}
