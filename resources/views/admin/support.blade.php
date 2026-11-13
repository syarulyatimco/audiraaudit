<x-layouts.app title="Support access">
    <x-page-header title="Support access" subtitle="Firms that have granted time-boxed, read-only support access. Viewing is recorded in the firm's audit trail." />
    <div class="card overflow-x-auto">
        @if ($grants->isEmpty())
            <x-empty title="No active grants" description="A firm admin must grant access from their firm settings before support can view anything." />
        @else
            <table class="table text-sm">
                <thead><tr><th>Firm</th><th>Reason</th><th>Granted by</th><th>Expires</th><th></th></tr></thead>
                <tbody>@foreach ($grants as $g)
                    <tr><td>{{ $g->firm->name }}</td><td>{{ $g->reason }}</td><td>{{ $g->granter->name }}</td><td>{{ local_time($g->expires_at) }}</td><td><a href="{{ route('admin.support.show', $g) }}" class="btn-secondary btn-sm">Open read-only view</a></td></tr>
                @endforeach</tbody>
            </table>
        @endif
    </div>
</x-layouts.app>
