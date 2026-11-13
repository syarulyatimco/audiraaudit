<x-layouts.app title="Approvals">
    <x-page-header title="Approvals" subtitle="Human approval gates. AI can prepare, but only authorised people decide — never on their own requests." />
    <div class="mb-4 flex gap-1 border-b border-navy-100">
        @foreach (\App\Enums\ApprovalStatus::cases() as $s)
            <a href="{{ route('approvals.index', ['status' => $s->value]) }}" class="-mb-px border-b-2 px-4 py-2 text-sm font-medium {{ $status === $s->value ? 'border-teal-600 text-navy-900' : 'border-transparent text-ink-500 hover:text-navy-900' }}">{{ $s->label() }}</a>
        @endforeach
    </div>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Type</th><th>Subject</th><th>Requested</th>@if($status !== 'pending')<th>Decision</th>@endif<th class="text-right">{{ $status === 'pending' ? 'Action' : '' }}</th></tr></thead>
            <tbody>
            @forelse ($approvals as $a)
                <tr>
                    <td><span class="text-2xs font-semibold uppercase tracking-wide text-navy-700">{{ $a->type->label() }}</span></td>
                    <td><a href="{{ $a->approvable->approvalUrl() }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $a->approvable->approvalTitle() }}</a>
                        @if ($a->request_comment)<div class="max-w-md truncate text-xs text-ink-500">{{ $a->request_comment }}</div>@endif</td>
                    <td class="whitespace-nowrap"><div>{{ $a->requester->name }}</div><div class="text-xs text-ink-500">{{ local_time($a->requested_at) }}</div></td>
                    @if ($status !== 'pending')
                        <td class="whitespace-nowrap"><x-status :value="$a->status" /><div class="text-xs text-ink-500">{{ $a->decider?->name }} {{ $a->decided_at ? '· '.local_time($a->decided_at) : '' }}</div></td>
                    @endif
                    <td class="text-right">
                        @if ($a->isPending())
                            @if ($service->canDecide($user, $a))
                                <a href="{{ $a->approvable->approvalUrl() }}" class="btn-accent btn-sm">Review & decide</a>
                            @elseif ($a->requested_by === $user->id)
                                <form method="POST" action="{{ route('approvals.withdraw', $a) }}" class="inline">@csrf<button class="btn-secondary btn-sm">Withdraw</button></form>
                            @else
                                <span class="text-xs text-ink-400">Awaiting an authorised approver</span>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><x-empty title="Nothing here" description="{{ $status === 'pending' ? 'No approvals are waiting.' : 'No approvals with this status.' }}" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $approvals->links() }}</div>
</x-layouts.app>
