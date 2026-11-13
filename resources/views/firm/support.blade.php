<x-layouts.app title="Support access">
    <x-page-header title="Support access" subtitle="Let AUDIRA platform support see a read-only summary of your firm for a limited time — users, engagement codes and statuses, and recent audit-trail events. Support never sees documents, working papers, client correspondence or financial data, and every view is recorded in your audit trail." />
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2 overflow-x-auto">
            <div class="card-header"><h2 class="card-title">Grants</h2></div>
            @if ($grants->isEmpty())
                <x-empty title="Support has never had access" description="Grant access only when you have asked AUDIRA support for help." />
            @else
                <table class="table text-sm">
                    <thead><tr><th>Granted</th><th>Reason</th><th>Until</th><th>Views</th><th></th></tr></thead>
                    <tbody>@foreach ($grants as $g)
                        <tr><td>{{ $g->granter->name }}<div class="text-xs text-ink-500">{{ local_time($g->created_at) }}</div></td><td>{{ $g->reason }}</td>
                            <td>{{ local_time($g->expires_at) }}@if($g->revoked_at)<div class="text-xs text-ink-500">Revoked {{ local_time($g->revoked_at) }}</div>@endif</td><td class="tabular-nums">{{ $g->views }}</td>
                            <td>@if ($g->isActive())<form method="POST" action="{{ route('firm.support.revoke', $g) }}">@csrf<button class="btn-danger btn-sm">Revoke</button></form>@else<span class="badge badge-neutral">Ended</span>@endif</td></tr>
                    @endforeach</tbody>
                </table>
            @endif
        </div>
        <form method="POST" action="{{ route('firm.support.store') }}" class="card card-body space-y-3">@csrf
            <x-field name="reason" label="Why support needs access" type="textarea" rows="3" required />
            <x-select name="hours" label="For" :options="[1 => '1 hour', 4 => '4 hours', 24 => '24 hours', 72 => '72 hours (maximum)']" value="4" />
            <button class="btn-primary w-full">Grant read-only access</button>
        </form>
    </div>
</x-layouts.app>
