<?php

namespace App\Http\Controllers\Clients;

use App\Enums\ClientStatus;
use App\Enums\EntityType;
use App\Enums\ReportingFramework;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\ClientRequest;
use App\Models\Client;
use App\Models\Engagement;
use App\Services\Approvals\ApprovalService;
use App\Services\Clients\ClientService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Client::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ClientStatus::class)],
        ]);

        $clients = Client::query()
            ->withCount('engagements')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('registration_no', 'ilike', '%'.addcslashes($term, '%_\\').'%')))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('name')
            ->paginate(20)->withQueryString();

        return view('clients.index', compact('clients', 'filters'));
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Client::class);

        return view('clients.form', [
            'client' => new Client([
                'reporting_framework' => $request->user()->firm->default_framework,
                'fye_month' => 12, 'fye_day' => 31,
            ]),
            'entityTypes' => EntityType::options(),
            'frameworks' => ReportingFramework::options(),
        ]);
    }

    public function store(ClientRequest $request, ClientService $service): RedirectResponse
    {
        Gate::authorize('create', Client::class);
        $client = $service->create($request->validated(), $request->user());

        return redirect()->route('clients.show', $client)->with('status', 'Client created. Complete the acceptance assessment to start engagements.');
    }

    public function show(Request $request, Client $client, ApprovalService $approvals): View
    {
        Gate::authorize('view', $client);
        $client->load(['contacts', 'approvals.requester', 'approvals.decider', 'creator']);
        $user = $request->user();

        $engagements = Engagement::visibleTo($user)->where('client_id', $client->id)
            ->orderByDesc('period_end')->get();

        $pending = $client->approvals->first(fn ($a) => $a->isPending());

        return view('clients.show', [
            'client' => $client,
            'engagements' => $engagements,
            'pendingApproval' => $pending,
            'canDecide' => $pending && $approvals->canDecide($user, $pending),
        ]);
    }

    public function edit(Client $client): View
    {
        Gate::authorize('update', $client);

        return view('clients.form', [
            'client' => $client,
            'entityTypes' => EntityType::options(),
            'frameworks' => ReportingFramework::options(),
        ]);
    }

    public function update(ClientRequest $request, Client $client, ClientService $service): RedirectResponse
    {
        Gate::authorize('update', $client);
        $service->update($client, $request->validated(), $request->user());

        return redirect()->route('clients.show', $client)->with('status', 'Client updated.');
    }

    public function destroy(Request $request, Client $client, ClientService $service): RedirectResponse
    {
        Gate::authorize('delete', $client);
        $service->delete($client, $request->user());

        return redirect()->route('clients.index')->with('status', 'Client deleted.');
    }
}
