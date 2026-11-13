<?php

namespace App\Http\Controllers\TrialBalance;

use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Models\EngagementFsli;
use App\Services\TrialBalance\FsliService;
use App\Services\TrialBalance\LeadScheduleService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class LeadScheduleController extends Controller
{
    public function index(Engagement $engagement, LeadScheduleService $schedules): View
    {
        Gate::authorize('view', $engagement);

        return view('trial-balance.lead-schedules', [
            'engagement' => $engagement->load('client'),
            'data' => $schedules->build($engagement),
            'aiRun' => $engagement->aiRuns()->where('agent', 'accounting_tb')->where('task', 'review_tb')->first(),
            'aiUnavailable' => app(\App\Ai\Runtime\AiOrchestrator::class)->unavailableReasons($engagement, 'accounting_tb'),
        ]);
    }

    public function show(Request $request, Engagement $engagement, EngagementFsli $fsli, LeadScheduleService $schedules): View
    {
        Gate::authorize('view', $engagement);
        $data = $schedules->build($engagement);

        return view('trial-balance.lead-schedule', [
            'engagement' => $engagement->load('client'),
            'schedule' => $data['fslis'][$fsli->id],
            'canCreatePaper' => ! $engagement->status->isLocked() && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.edit_working_papers'),
        ]);
    }

    public function createPaper(Request $request, Engagement $engagement, EngagementFsli $fsli, FsliService $fslis): RedirectResponse
    {
        Gate::authorize('view', $engagement);
        abort_unless(! $engagement->status->isLocked() && Permissions::allowsOnEngagement($request->user(), $engagement, 'engagement.edit_working_papers'), 403);
        $wp = $fslis->createLeadPaper($fsli, $request->user());

        return redirect()->route('working-papers.show', [$engagement, $wp])->with('status', 'Lead schedule working paper created. Its sign-off covers the FSLI figures.');
    }
}
