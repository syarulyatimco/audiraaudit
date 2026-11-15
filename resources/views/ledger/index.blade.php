<x-layouts.app :title="'General ledger · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-tb-nav :engagement="$engagement" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if (! $active)
                <div class="card"><x-empty title="No general ledger imported" description="Import the client's GL to reconcile it to the trial balance, drill into balances, test journal entries and sample postings." /></div>
            @else
                @php $r = $active->reconciliation; @endphp
                <div class="card">
                    <div class="card-header">
                        <div><h2 class="card-title">Reconciliation to the trial balance</h2>
                            <p class="text-xs text-ink-500">Per account: opening + GL movements = TB closing balance. Opening comes from "balance b/f" rows in the GL, else the prior-year TB (balance-sheet accounts) or nil (P&amp;L).</p></div>
                        <span class="badge {{ $r['differences'] ? 'badge-warn' : 'badge-success' }}">{{ $r['differences'] ? $r['differences'].' to explain' : 'All agreed' }}</span>
                    </div>
                    @unless ($r['tb_available'])<p class="mx-5 mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">No current-year TB is active, so closing balances cannot be compared yet.</p>@endunless
                    @unless ($r['balanced'])<p class="mx-5 mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">The GL's total debits ({{ amount($r['summary']['debits'], false) }}) and credits ({{ amount($r['summary']['credits'], false) }}) differ. The export may be incomplete.</p>@endunless
                    <div class="flex gap-2 px-5 pt-3 text-xs">
                        <a href="{{ route('ledger.index', [$engagement, 'show' => 'differences']) }}" class="{{ $filter !== 'all' ? 'font-semibold text-navy-900' : 'text-ink-500' }}">Differences</a> ·
                        <a href="{{ route('ledger.index', [$engagement, 'show' => 'all']) }}" class="{{ $filter === 'all' ? 'font-semibold text-navy-900' : 'text-ink-500' }}">All {{ $r['summary']['accounts'] }} accounts</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead><tr><th>Account</th><th class="text-right">Opening</th><th class="text-right">Movements</th><th class="text-right">GL closing</th><th class="text-right">TB closing</th><th class="text-right">Difference</th></tr></thead>
                            <tbody>
                            @forelse (collect($r['accounts'])->when($filter !== 'all', fn ($c) => $c->where('status', '!=', 'agreed')) as $a)
                                <tr>
                                    <td><a href="{{ route('ledger.accounts', [$engagement, 'account' => $a['code']]) }}" class="font-mono text-xs font-semibold text-navy-800 hover:text-teal-700">{{ $a['code'] }}</a> <span class="text-xs text-ink-500">{{ $a['name'] }}</span>
                                        <div class="text-2xs text-ink-400">opening: {{ $a['opening_source'] }} · {{ $a['lines'] }} postings</div></td>
                                    <td class="text-right tabular-nums">{{ amount($a['opening']) }}</td>
                                    <td class="text-right tabular-nums">{{ amount($a['movement']) }}</td>
                                    <td class="text-right tabular-nums">{{ amount($a['gl_closing']) }}</td>
                                    <td class="text-right tabular-nums">{{ $a['tb_closing'] === null ? '—' : amount($a['tb_closing']) }}</td>
                                    <td class="text-right tabular-nums font-medium {{ $a['status'] === 'agreed' ? 'text-emerald-700' : 'text-red-700' }}">{{ ['agreed' => '✓', 'difference' => amount($a['difference'], false), 'missing_in_tb' => 'not in TB', 'missing_in_gl' => 'no postings'][$a['status']] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-5 py-6 text-center text-sm text-emerald-700">Every account agrees to the trial balance.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div class="card overflow-x-auto">
                <div class="card-header"><h2 class="card-title">Journal entry tests</h2><a href="{{ route('ledger.jet', $engagement) }}" class="btn-secondary btn-sm">Journal entry testing</a></div>
                @if ($tests->isEmpty())
                    <p class="px-5 py-4 text-sm text-ink-500">No tests saved yet.</p>
                @else
                    <table class="table"><thead><tr><th>Ref</th><th>Test</th><th class="text-right">Entries</th><th class="text-right">Exceptions</th><th>Status</th></tr></thead><tbody>
                        @foreach ($tests as $t)
                            <tr class="row-link"><td class="font-mono text-xs font-semibold">{{ $t->reference }}</td><td><a href="{{ route('ledger.jet.show', [$engagement, $t]) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $t->title }}</a></td>
                                <td class="text-right tabular-nums">{{ $t->items_count }}</td><td class="text-right tabular-nums {{ $t->exceptions_count ? 'text-red-700' : '' }}">{{ $t->exceptions_count }}</td>
                                <td><span class="badge {{ $t->status === 'concluded' ? 'badge-success' : 'badge-info' }}">{{ ucfirst($t->status) }}</span></td></tr>
                        @endforeach
                    </tbody></table>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            @if ($active)
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Imported ledger</h2></div>
                    <dl class="card-body text-sm">
                        <div class="dl-row"><dt>Postings</dt><dd>{{ number_format($active->line_count) }}</dd></div>
                        <div class="dl-row"><dt>Dates</dt><dd>{{ $active->first_date?->format('j M Y') }} – {{ $active->last_date?->format('j M Y') }}</dd></div>
                        <div class="dl-row"><dt>Total debits</dt><dd>{{ amount($active->debit_sen, false) }}</dd></div>
                        <div class="dl-row"><dt>Total credits</dt><dd>{{ amount($active->credit_sen, false) }}</dd></div>
                        <div class="dl-row"><dt>Source</dt><dd><a href="{{ route('documents.show', [$engagement, $active->document]) }}" class="text-teal-700 hover:underline">{{ \Illuminate\Support\Str::limit($active->document->title, 30) }}</a></dd></div>
                    </dl>
                </div>
            @endif
            @if ($canImport)
                <form method="POST" action="{{ route('ledger.upload', $engagement) }}" enctype="multipart/form-data" class="card">
                    @csrf
                    <div class="card-header"><h2 class="card-title">{{ $active ? 'Re-import the GL' : 'Import the GL' }}</h2></div>
                    <div class="card-body space-y-3 text-sm">
                        @include('ledger.partials.source')
                        <p class="hint">One row per posting with a date, account code and debit / credit (or a signed amount). Column headings from common accounting software exports are recognised; you confirm them on the next screen.</p>
                        <button class="btn-primary w-full">Upload and check columns</button>
                    </div>
                </form>
            @endif
            @php $pending = $imports->where('status', 'pending'); @endphp
            @if ($pending->isNotEmpty())
                <div class="card"><div class="card-header"><h2 class="card-title">Awaiting column check</h2></div>
                    <ul class="divide-y divide-navy-50 text-sm">@foreach ($pending as $i)<li class="px-5 py-2.5"><a href="{{ route('ledger.configure', [$engagement, $i]) }}" class="text-teal-700 hover:underline">Uploaded {{ local_time($i->created_at, 'j M, g:i a') }} by {{ $i->importer->name }}</a></li>@endforeach</ul></div>
            @endif
        </div>
    </div>
</x-layouts.app>
