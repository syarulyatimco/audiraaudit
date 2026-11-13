<?php

namespace App\Http\Controllers\TrialBalance;

use App\Enums\FsliClassification;
use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Models\EngagementFsli;
use App\Services\TrialBalance\FsliService;
use App\Services\TrialBalance\LeadScheduleService;
use App\Services\TrialBalance\MappingService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MappingController extends Controller
{
    public function __construct(private MappingService $mapping, private FsliService $fslis) {}

    public function index(Request $request, Engagement $engagement, LeadScheduleService $schedules): View
    {
        Gate::authorize('view', $engagement);
        $filter = $request->validate(['show' => ['nullable', Rule::in(['all', 'unmapped'])]])['show'] ?? 'all';
        $accounts = $schedules->accounts($engagement);
        $canMap = $this->can($request, $engagement, 'engagement.map_accounts');

        return view('trial-balance.mapping', [
            'engagement' => $engagement->load('client'),
            'accounts' => $filter === 'unmapped' ? $accounts->whereNull('fsli_id') : $accounts,
            'unmappedCount' => $accounts->whereNull('fsli_id')->count(),
            'total' => $accounts->count(),
            'fslis' => $engagement->fslis()->withCount('mappings')->get(),
            'suggestions' => $canMap ? $this->mapping->suggestions($engagement) : [],
            'filter' => $filter,
            'canMap' => $canMap,
            'canManageFslis' => $this->can($request, $engagement, 'engagement.manage_fslis'),
            'aiRun' => $engagement->aiRuns()->where('agent', 'accounting_tb')->where('task', 'suggest_mappings')->first(),
            'aiUnavailable' => app(\App\Ai\Runtime\AiOrchestrator::class)->unavailableReasons($engagement, 'accounting_tb'),
        ]);
    }

    public function update(Request $request, Engagement $engagement): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($this->can($request, $engagement, 'engagement.map_accounts'), 403);
        $data = $request->validate(['map' => ['required', 'array', 'max:5000'], 'map.*' => ['nullable', 'integer']]);
        $map = array_map(fn ($v) => $v === null || $v === '' ? null : (int) $v, $data['map']);

        $n = $this->mapping->apply($engagement, $map, $request->user());

        return back()->with('status', $n ? "{$n} mapping(s) saved." : 'No changes.');
    }

    public function accept(Request $request, Engagement $engagement): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($this->can($request, $engagement, 'engagement.map_accounts'), 403);
        $data = $request->validate(['codes' => ['required', 'array', 'max:5000'], 'codes.*' => ['string', 'max:50']]);
        $n = $this->mapping->acceptSuggestions($engagement, $data['codes'], $request->user());

        return back()->with('status', "{$n} suggestion(s) accepted.");
    }

    public function storeFsli(Request $request, Engagement $engagement): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($this->can($request, $engagement, 'engagement.manage_fslis'), 403);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-_]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'classification' => ['required', Rule::enum(FsliClassification::class)],
        ]);
        $this->fslis->add($engagement, $data, $request->user());

        return back()->with('status', 'FSLI added.');
    }

    public function updateFsli(Request $request, Engagement $engagement, EngagementFsli $fsli): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($this->can($request, $engagement, 'engagement.manage_fslis'), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:160']]);
        $this->fslis->rename($fsli, $data['name'], $request->user());

        return back()->with('status', 'FSLI renamed.');
    }

    public function destroyFsli(Request $request, Engagement $engagement, EngagementFsli $fsli): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless($this->can($request, $engagement, 'engagement.manage_fslis'), 403);
        $this->fslis->delete($fsli, $request->user());

        return back()->with('status', 'FSLI deleted.');
    }

    private function can(Request $request, Engagement $engagement, string $perm): bool
    {
        return ! $engagement->status->isLocked() && Permissions::allowsOnEngagement($request->user(), $engagement, $perm);
    }
}
