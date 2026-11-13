<?php

namespace App\Http\Controllers\Clients;

use App\Enums\AcceptanceRisk;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Clients\ClientService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ClientAcceptanceController extends Controller
{
    public function store(Request $request, Client $client, ClientService $service): RedirectResponse
    {
        Gate::authorize('submitAcceptance', $client);

        $data = $request->validate([
            'acceptance_risk' => ['required', Rule::enum(AcceptanceRisk::class)],
            'independence_confirmed' => ['accepted'],
            'acceptance_notes' => ['required', 'string', 'min:20', 'max:5000'],
        ], [
            'independence_confirmed.accepted' => 'Independence must be confirmed before acceptance can be requested.',
            'acceptance_notes.min' => 'Please document the basis for acceptance (at least 20 characters).',
        ]);
        $data['independence_confirmed'] = true;

        $service->submitForAcceptance($client, $data, $request->user());

        return back()->with('status', 'Acceptance assessment submitted for partner approval.');
    }
}
