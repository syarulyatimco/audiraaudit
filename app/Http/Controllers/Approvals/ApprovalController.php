<?php

namespace App\Http\Controllers\Approvals;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Services\Approvals\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApprovalController extends Controller
{
    public function index(Request $request, ApprovalService $service): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(ApprovalStatus::class)]]);
        $status = $filters['status'] ?? ApprovalStatus::Pending->value;

        $approvals = Approval::visibleTo($request->user())->with(['approvable', 'requester', 'decider'])
            ->where('status', $status)
            ->latest('requested_at')
            ->paginate(20)->withQueryString();

        return view('approvals.index', [
            'approvals' => $approvals,
            'status' => $status,
            'service' => $service,
            'user' => $request->user(),
        ]);
    }

    public function decide(Request $request, Approval $approval, ApprovalService $service): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'decision_comment' => ['nullable', 'string', 'max:5000'],
            'password' => ['nullable', 'string', 'max:200'],
            'mfa_code' => ['nullable', 'string', 'max:20'],
        ]);
        $approve = $data['decision'] === 'approve';
        $reauth = false;
        if ($approve && ApprovalService::requiresReauth($approval)) {
            // Re-authentication at the moment of decision (opinion and report sign-off).
            $user = $request->user();
            if (! \Illuminate\Support\Facades\Hash::check((string) ($data['password'] ?? ''), $user->password)) {
                app(\App\Support\Audit\ActivityLogger::class)->log('approval.reauth_failed', 'Re-authentication failed for '.$approval->type->label(), $approval->approvable, ['approval' => $approval->uuid], $user);
                throw \Illuminate\Validation\ValidationException::withMessages(['password' => 'The password is incorrect.']);
            }
            if ($user->hasMfaEnabled() && ! app(\App\Services\Auth\MfaService::class)->verify($user, (string) ($data['mfa_code'] ?? ''))) {
                throw \Illuminate\Validation\ValidationException::withMessages(['mfa_code' => 'Enter a valid authentication code.']);
            }
            $reauth = true;
        }

        $service->decide($approval, $request->user(), $approve, $data['decision_comment'] ?? null, $reauth);

        return back()->with('status', $data['decision'] === 'approve' ? 'Approved.' : 'Rejected.');
    }

    public function withdraw(Request $request, Approval $approval, ApprovalService $service): RedirectResponse
    {
        $service->cancel($approval, $request->user());

        return back()->with('status', 'Request withdrawn.');
    }
}
