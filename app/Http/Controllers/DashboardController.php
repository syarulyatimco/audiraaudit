<?php

namespace App\Http\Controllers;

use App\Enums\ClientStatus;
use App\Enums\EngagementStatus;
use App\Models\ActivityLog;
use App\Models\Approval;
use App\Models\Client;
use App\Models\Engagement;
use App\Services\Approvals\ApprovalService;
use App\Services\Billing\BillingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ApprovalService $approvals, BillingService $billing): View
    {
        $user = $request->user();

        $engagements = Engagement::visibleTo($user)
            ->with(['client', 'members.user'])
            ->whereNotIn('status', [EngagementStatus::Archived->value])
            ->orderBy('reporting_deadline')->orderByDesc('period_end')
            ->limit(8)->get();

        $pending = Approval::pending()->visibleTo($request->user())->with(['approvable', 'requester'])->latest('requested_at')->get();
        $awaitingMe = $pending->filter(fn (Approval $a) => $approvals->canDecide($user, $a));

        $stats = [
            'active_engagements' => Engagement::visibleTo($user)->whereIn('status', array_map(fn ($s) => $s->value, EngagementStatus::workingStages()))->count(),
            'accepted_clients' => Client::where('status', ClientStatus::Accepted->value)->count(),
            'pending_acceptance' => Client::where('status', ClientStatus::PendingAcceptance->value)->count(),
            'awaiting_me' => $awaitingMe->count(),
        ];

        $activity = $user->hasPermission('activity.view')
            ? ActivityLog::latest('created_at')->limit(6)->get()
            : collect();

        return view('dashboard', [
            'stats' => $stats,
            'engagements' => $engagements,
            'awaitingMe' => $awaitingMe->take(5),
            'activity' => $activity,
            'firm' => $user->firm,
            'billing' => $billing->estimate($user->firm),
        ]);
    }
}
