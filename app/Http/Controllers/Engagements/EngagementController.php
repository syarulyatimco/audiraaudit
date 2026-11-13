<?php

namespace App\Http\Controllers\Engagements;

use App\Enums\ClientStatus;
use App\Enums\EngagementRole;
use App\Enums\EngagementStatus;
use App\Enums\EngagementType;
use App\Enums\FirmRole;
use App\Enums\ReportingFramework;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Engagements\EngagementRequest;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Engagement;
use App\Services\Engagements\EngagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EngagementController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Engagement::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(EngagementStatus::class)],
        ]);

        $engagements = Engagement::visibleTo($request->user())
            ->with(['client', 'members.user'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('code', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhereHas('client', fn ($c) => $c->where('name', 'ilike', '%'.addcslashes($term, '%_\\').'%'))))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('period_end')
            ->paginate(20)->withQueryString();

        return view('engagements.index', compact('engagements', 'filters'));
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Engagement::class);
        $firm = $request->user()->firm;

        $clients = Client::where('status', ClientStatus::Accepted->value)->orderBy('name')->get();
        $staff = $firm->staff()->where('status', UserStatus::Active->value)->orderBy('name')->get();

        return view('engagements.create', [
            'clients' => $clients,
            'selectedClient' => $clients->firstWhere('uuid', $request->query('client')),
            'partners' => $staff->where('firm_role', FirmRole::Partner),
            'managers' => $staff->whereIn('firm_role', [FirmRole::Partner, FirmRole::Manager]),
            'types' => EngagementType::options(),
            'frameworks' => ReportingFramework::options(),
            'currentUser' => $request->user(),
        ]);
    }

    public function store(EngagementRequest $request, EngagementService $service): RedirectResponse
    {
        Gate::authorize('create', Engagement::class);
        $data = $request->validated();
        $client = Client::findOrFail($data['client_id']); // tenant-scoped lookup

        $engagement = $service->create($client, $data, $request->user());

        return redirect()->route('engagements.show', $engagement)->with('status', 'Engagement created.');
    }

    public function show(Request $request, Engagement $engagement): View
    {
        Gate::authorize('view', $engagement);
        $engagement->load(['client', 'members.user', 'creator']);
        $user = $request->user();

        $memberIds = $engagement->members->pluck('user_id');
        $available = $user->firm->staff()->where('status', UserStatus::Active->value)
            ->whereNotIn('id', $memberIds)->orderBy('name')->get();

        $activity = ActivityLog::where('subject_type', $engagement->getMorphClass())
            ->where('subject_id', $engagement->id)->latest('created_at')->limit(15)->get();

        return view('engagements.show', [
            'engagement' => $engagement,
            'availableStaff' => $available,
            'partners' => $user->firm->staff()->where('status', UserStatus::Active->value)->where('firm_role', FirmRole::Partner->value)->orderBy('name')->get(),
            'roles' => collect(EngagementRole::cases())->reject(fn ($r) => $r === EngagementRole::EngagementPartner),
            'activity' => $activity,
            'alerts' => $engagement->alerts()->open()->orderByRaw("case severity when 'danger' then 0 when 'warn' then 1 else 2 end")->get(),
            'healthRun' => $engagement->aiRuns()->where('agent', 'monitoring')->where('task', 'health_digest')->first(),
            'healthUnavailable' => app(\App\Ai\Runtime\AiOrchestrator::class)->unavailableReasons($engagement, 'monitoring'),
            'canManageTeam' => $user->can('manageTeam', $engagement) && ! $engagement->status->isLocked(),
            'canChangeStatus' => $user->can('changeStatus', $engagement) && ! $engagement->status->isLocked(),
            'canUpdate' => $user->can('update', $engagement) && ! $engagement->status->isLocked(),
            'myRole' => $engagement->roleOf($user),
        ]);
    }

    public function edit(Engagement $engagement): View
    {
        Gate::authorize('update', $engagement);

        return view('engagements.edit', [
            'engagement' => $engagement->load('client'),
            'types' => EngagementType::options(),
            'frameworks' => ReportingFramework::options(),
        ]);
    }

    public function update(EngagementRequest $request, Engagement $engagement, EngagementService $service): RedirectResponse
    {
        Gate::authorize('update', $engagement);
        $service->update($engagement, $request->validated(), $request->user());

        return redirect()->route('engagements.show', $engagement)->with('status', 'Engagement updated.');
    }

    public function changeStatus(Request $request, Engagement $engagement, EngagementService $service): RedirectResponse
    {
        Gate::authorize('changeStatus', $engagement);
        $data = $request->validate(['status' => ['required', Rule::enum(EngagementStatus::class)]]);

        $service->changeStatus($engagement, EngagementStatus::from($data['status']), $request->user());

        return back()->with('status', 'Engagement status updated.');
    }
}
