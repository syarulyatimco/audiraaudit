{{-- Pending approval panel: $pendingApproval, $canDecide, $approveLabel --}}
@if ($pendingApproval)
    <div class="rounded-md border border-amber-200 bg-amber-50 p-4">
        <div class="text-sm font-semibold text-amber-900">Awaiting engagement partner approval</div>
        <p class="mt-1 text-sm text-amber-900/80">Submitted by {{ $pendingApproval->requester->name }} {{ $pendingApproval->requested_at->diffForHumans() }}.</p>
        @if ($pendingApproval->request_comment)<p class="mt-2 whitespace-pre-line text-sm text-ink-700">{{ $pendingApproval->request_comment }}</p>@endif
        @if ($canDecide)
            <form method="POST" action="{{ route('approvals.decide', $pendingApproval) }}" class="mt-4 space-y-3" x-data="{ decision: 'approve' }">
                @csrf
                <x-field name="decision_comment" label="Decision note" type="textarea" rows="2" hint="Required when returning it." />
                @if (\App\Services\Approvals\ApprovalService::requiresReauth($pendingApproval))
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-field name="password" label="Confirm your password to approve" type="password" autocomplete="current-password" />
                        @if (auth()->user()->hasMfaEnabled())<x-field name="mfa_code" label="Authentication code" inputmode="numeric" autocomplete="one-time-code" />@endif
                    </div>
                @endif
                <input type="hidden" name="decision" :value="decision">
                <div class="flex gap-2">
                    <button class="btn-accent" @click="decision = 'approve'">{{ $approveLabel }}</button>
                    <button class="btn-danger" @click="decision = 'reject'">Return for changes</button>
                </div>
            </form>
        @elseif ($pendingApproval->requested_by === auth()->id())
            <form method="POST" action="{{ route('approvals.withdraw', $pendingApproval) }}" class="mt-4">@csrf<button class="btn-secondary btn-sm">Withdraw request</button></form>
        @else
            <p class="mt-3 text-xs text-amber-900/70">Only the engagement partner can decide this request.</p>
        @endif
    </div>
@endif
