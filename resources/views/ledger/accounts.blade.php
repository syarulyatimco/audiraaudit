<x-layouts.app :title="'GL postings · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-tb-nav :engagement="$engagement" />
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <a href="{{ route('ledger.index', $engagement) }}" class="text-sm text-ink-500 hover:text-navy-900">← General ledger</a>
        <span class="text-sm font-medium text-navy-900">{{ $fsli ? $fsli->code.' · '.$fsli->name : 'Account '.implode(', ', $codes) }}</span>
        <span class="text-xs text-ink-500">{{ number_format($totals->n) }} postings · Dr {{ amount($totals->dr, false) }} · Cr {{ amount($totals->cr, false) }}</span>
        <form class="ml-auto flex gap-2" method="GET">
            @if ($fsli)<input type="hidden" name="fsli" value="{{ $fsli->code }}">@else<input type="hidden" name="account" value="{{ $codes[0] ?? '' }}">@endif
            <input name="q" value="{{ request('q') }}" placeholder="Search description or reference" class="input !py-1.5 text-sm"><button class="btn-secondary btn-sm">Search</button>
        </form>
    </div>
    <div class="card overflow-x-auto">
        <table class="table text-sm">
            <thead><tr><th>Date</th><th>Account</th><th>Reference</th><th>Description</th><th>Journal</th><th>User</th><th class="text-right">Debit</th><th class="text-right">Credit</th></tr></thead>
            <tbody>
            @forelse ($lines as $l)
                <tr class="{{ $l->is_opening ? 'bg-navy-50/40 italic' : '' }}"><td class="whitespace-nowrap">{{ $l->is_opening ? 'b/f' : $l->posted_on->format('d/m/Y') }}</td><td class="font-mono text-xs">{{ $l->account_code }}</td><td class="text-xs">{{ $l->reference }}</td>
                    <td class="max-w-md truncate" title="{{ $l->description }}">{{ $l->description }}</td><td class="text-xs">{{ $l->journal_type }}</td><td class="text-xs">{{ $l->posted_by }}</td>
                    <td class="text-right tabular-nums">{{ $l->debit_sen ? amount($l->debit_sen, false) : '' }}</td><td class="text-right tabular-nums">{{ $l->credit_sen ? amount($l->credit_sen, false) : '' }}</td></tr>
            @empty
                <tr><td colspan="8" class="px-5 py-6 text-center text-ink-500">No postings.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="px-5 py-3">{{ $lines->links() }}</div>
    </div>
</x-layouts.app>
