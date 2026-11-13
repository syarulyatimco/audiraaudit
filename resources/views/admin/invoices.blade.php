<x-layouts.app title="Invoices">
    <x-page-header title="Invoices" :subtitle="'Outstanding RM '.amount($outstanding, false)" />
    <div class="card overflow-x-auto">
        <div class="card-header">
            <div class="inline-flex rounded-md border border-navy-100 bg-white p-0.5 text-xs">
                @foreach ([null => 'All', 'issued' => 'Issued', 'paid' => 'Paid', 'void' => 'Void'] as $k => $l)
                    <a href="{{ route('admin.invoices.index', array_filter(['status' => $k])) }}" class="rounded px-2.5 py-1 {{ $status === ($k ?: null) ? 'bg-navy-800 text-white' : 'text-ink-600' }}">{{ $l }}</a>
                @endforeach
            </div>
        </div>
        @if ($invoices->isEmpty())
            <x-empty title="No invoices" description="Invoices are issued by the daily billing run, or from a firm's page." />
        @else
            <table class="table text-sm">
                <thead><tr><th>Number</th><th>Firm</th><th>Period</th><th>Due</th><th class="text-right">Total (RM)</th><th>Status</th></tr></thead>
                <tbody>@foreach ($invoices as $inv)
                    <tr class="row-link"><td><a class="link font-mono text-xs" href="{{ route('admin.invoices.show', $inv) }}">{{ $inv->number }}</a></td><td>{{ $inv->firm->name }}</td>
                        <td>{{ $inv->period_start->format('j M Y') }} – {{ $inv->period_end->format('j M Y') }}</td><td class="{{ $inv->isOverdue() ? 'font-semibold text-red-700' : '' }}">{{ $inv->due_on->format('j M Y') }}</td>
                        <td class="text-right tabular-nums">{{ amount($inv->total_sen, false) }}</td><td><x-status :value="$inv->isOverdue() ? 'past_due' : $inv->status" /></td></tr>
                @endforeach</tbody>
            </table>
            <div class="px-5 py-3">{{ $invoices->links() }}</div>
        @endif
    </div>
</x-layouts.app>
