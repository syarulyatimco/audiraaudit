<?php

namespace App\Http\Controllers\Firm;

use App\Http\Controllers\Controller;
use App\Models\SupportAccessGrant;
use App\Support\Audit\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Firm admins grant (and revoke) time-boxed, read-only, metadata-only access for platform support. */
class SupportAccessController extends Controller
{
    public function index(): View
    {
        Gate::authorize('support.grant');

        return view('firm.support', ['grants' => SupportAccessGrant::with('granter')->latest()->limit(20)->get()]);
    }

    public function store(Request $request, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('support.grant');
        $max = (int) config('audira.support_access_max_hours');
        $d = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000'], 'hours' => ['required', 'integer', 'min:1', "max:{$max}"]]);
        if (SupportAccessGrant::active()->exists()) {
            return back()->withErrors(['reason' => 'Support access is already granted. Revoke it before granting again.']);
        }
        $g = SupportAccessGrant::create(['granted_by' => $request->user()->id, 'reason' => trim($d['reason']), 'expires_at' => now()->addHours((int) $d['hours'])]);
        $logger->log('support.granted', "Support access granted for {$d['hours']} hour(s)", $g, ['reason' => $g->reason, 'expires_at' => $g->expires_at->toIso8601String()]);

        return back()->with('status', 'Support access granted until '.local_time($g->expires_at).'.');
    }

    public function revoke(Request $request, SupportAccessGrant $grant, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('support.grant');
        abort_unless($grant->isActive(), 422, 'This grant is no longer active.');
        $grant->forceFill(['revoked_at' => now(), 'revoked_by' => $request->user()->id])->save();
        $logger->log('support.revoked', 'Support access revoked', $grant);

        return back()->with('status', 'Support access revoked.');
    }
}
