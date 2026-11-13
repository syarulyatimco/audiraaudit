<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Engagement;
use App\Models\SupportAccessGrant;
use App\Support\Audit\ActivityLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Break-glass support view: only while the firm's grant is active; read-only; metadata only
 * (no documents, working-paper content, client correspondence or financial data). Every view is
 * written to the firm's own audit trail.
 */
class SupportViewController extends Controller
{
    public function index(): View
    {
        return view('admin.support', ['grants' => SupportAccessGrant::with(['firm', 'granter'])->active()->orderBy('expires_at')->get()]);
    }

    public function show(Request $request, SupportAccessGrant $grant, TenantContext $tenant, ActivityLogger $logger): View
    {
        abort_unless($grant->isActive(), 403, 'This support access grant has expired or was revoked.');
        $grant->forceFill(['views' => $grant->views + 1, 'last_viewed_at' => now()])->save();
        $logger->log('support.viewed', 'Platform support viewed the firm (read-only, metadata)', $grant, ['by' => $request->user()->email], $request->user(), $grant->firm_id);

        $data = $tenant->runForFirm($grant->firm_id, fn () => [
            'firm' => $grant->firm()->first(),
            'users' => \App\Models\User::where('firm_id', $grant->firm_id)->orderBy('type')->orderBy('name')->get(['name', 'type', 'firm_role', 'is_firm_admin', 'status', 'mfa_enabled_at', 'last_login_at']),
            'engagements' => Engagement::orderByDesc('id')->limit(50)->get(['code', 'status', 'reporting_framework', 'period_end', 'reporting_deadline']),
            'statusCounts' => Engagement::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'activity' => ActivityLog::latest('id')->limit(30)->get(['event', 'actor_label', 'created_at']),
        ]);

        return view('admin.support-view', ['grant' => $grant->load('granter')] + $data);
    }
}
