<?php

namespace App\Http\Controllers\Engagements;

use App\Enums\EngagementRole;
use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Models\EngagementMember;
use App\Models\User;
use App\Services\Engagements\EngagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EngagementTeamController extends Controller
{
    public function store(Request $request, Engagement $engagement, EngagementService $service): RedirectResponse
    {
        Gate::authorize('manageTeam', $engagement);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'role' => ['required', Rule::enum(EngagementRole::class), Rule::notIn([EngagementRole::EngagementPartner->value])],
        ]);
        $user = User::where('firm_id', $request->user()->firm_id)->findOrFail($data['user_id']);

        $service->addMember($engagement, $user, EngagementRole::from($data['role']), $request->user());

        return back()->with('status', "{$user->name} added to the team.");
    }

    public function destroy(Request $request, Engagement $engagement, EngagementMember $member, EngagementService $service): RedirectResponse
    {
        Gate::authorize('manageTeam', $engagement);
        $service->removeMember($member, $request->user());

        return back()->with('status', 'Team member removed.');
    }

    public function replacePartner(Request $request, Engagement $engagement, EngagementService $service): RedirectResponse
    {
        Gate::authorize('manageTeam', $engagement);
        abort_unless($request->user()->hasPermission('engagements.view_all'), 403, 'Only partners or firm admins can reassign the engagement partner.');

        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $user = User::where('firm_id', $request->user()->firm_id)->findOrFail($data['user_id']);
        $service->replacePartner($engagement, $user, $request->user());

        return back()->with('status', "{$user->name} is now the engagement partner.");
    }
}
