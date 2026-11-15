<x-layouts.app :title="$report->label().' · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-tb-nav :engagement="$engagement" />
    @php $party = $report->type === 'receivables' ? 'customer' : 'supplier'; $max = max(1, max(array_map(fn ($b) => abs($b['amount']), $a['buckets']))); @endphp
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <a href="{{ route('ageing.index', $engagement) }}" class="text-sm text-ink-500 hover:text-navy-900">← Ageing</a>
        <span class="text-sm font-medium text-navy-900">{{ $report->label() }}</span>
        <span class="text-xs text-ink-500">{{ number_format($a['count']) }} {{ $party }}s · source <a href="{{ route('documents.show', [$engagement, $report->document]) }}" class="text-teal-700 hover:underline">{{ \Illuminate\Support\Str::limit($report->document->title, 40) }}</a></span>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card">
                <div class="card-header"><h2 class="card-title">By age</h2><span class="text-sm font-semibold tabular-nums">{{ amount($a['total'], false) }}</span></div>
                <div class="card-body space-y-2 text-sm">
                    @foreach ($a['buckets'] as $i => $b)
                        <div class="grid grid-cols-12 items-center gap-3">
                            <div class="col-span-3 text-ink-700">{{ $b['label'] }} <span class="text-2xs text-ink-400">({{ $b['min_days'] }}+ d)</span></div>
                            <div class="col-span-6"><div class="h-3 rounded-sm bg-navy-50"><div class="h-3 rounded-sm {{ $b['min_days'] > 90 ? 'bg-amber-500' : 'bg-teal-500' }}" style="width: {{ round(abs($b['amount']) * 100 / $max) }}%"></div></div></div>
                            <div class="col-span-2 text-right tabular-nums">{{ amount($b['amount']) }}</div>
                            <div class="col-span-1 text-right text-xs text-ink-500">{{ $b['pct'] }}%</div>
                        </div>
                    @endforeach
                    @if ($a['prior'])
                        <p class="pt-3 text-xs text-ink-500">Prior year listing at {{ $a['prior']['as_at']->format('j M Y') }}: total {{ amount($a['prior']['total'], false) }} —
                            {{ collect($a['prior']['analysis'])->map(fn ($b) => $b['label'].' '.amount($b['amount'], false))->join(' · ') }}</p>
                    @endif
                </div>
            </div>

            <div class="card overflow-x-auto">
                <div class="card-header"><h2 class="card-title">Largest {{ $party }}s</h2><span class="text-xs text-ink-500">top 10 = {{ $a['top_share'] }}% of the total</span></div>
                <table class="table text-sm">
                    <thead><tr><th>{{ ucfirst($party) }}</th>@foreach ($report->buckets as $b)<th class="text-right">{{ $b['label'] }}</th>@endforeach<th class="text-right">Total</th></tr></thead>
                    <tbody>
                    @foreach ($a['top'] as $l)
                        <tr><td><span class="font-mono text-xs">{{ $l->party_code }}</span> {{ $l->party_name }}</td>@foreach ($l->bucket_sen as $v)<td class="text-right tabular-nums">{{ $v ? amount($v) : '' }}</td>@endforeach<td class="text-right font-medium tabular-nums">{{ amount($l->total_sen) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if ($a['wrong_side']->isNotEmpty())
                <div class="card overflow-x-auto">
                    <div class="card-header"><h2 class="card-title">{{ $report->type === 'receivables' ? 'Credit balances' : 'Debit balances' }}</h2><span class="text-xs text-amber-700">{{ $a['wrong_side']->count() }} — consider reclassification</span></div>
                    <table class="table text-sm"><tbody>@foreach ($a['wrong_side'] as $l)<tr><td><span class="font-mono text-xs">{{ $l->party_code }}</span> {{ $l->party_name }}</td><td class="text-right tabular-nums text-red-700">{{ amount($l->total_sen) }}</td></tr>@endforeach</tbody></table>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Reconciliation</h2></div>
                <dl class="card-body text-sm">
                    <div class="dl-row"><dt>Listing total</dt><dd>{{ amount($a['total'], false) }}</dd></div>
                    @if ($a['recon'])
                        <div class="dl-row"><dt>{{ $a['recon']['fsli'] }} ({{ $report->period === 'prior' ? 'prior year TB' : 'unadjusted TB' }})</dt><dd>{{ amount($a['recon']['tb'], false) }}</dd></div>
                        <div class="dl-row"><dt>Difference</dt><dd class="{{ $a['recon']['difference'] ? 'text-red-700' : 'text-emerald-700' }}">{{ $a['recon']['difference'] ? amount($a['recon']['difference'], false) : 'Agreed ✓' }}</dd></div>
                        @if ($a['recon']['adjusted'] !== null)<div class="dl-row"><dt>After adjustments</dt><dd>{{ amount($a['recon']['adjusted'], false) }}</dd></div>@endif
                    @else
                        <p class="text-xs text-ink-500">No FSLI linked, or the TB is not imported yet.</p>
                    @endif
                </dl>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Overdue</h2></div>
                <dl class="card-body text-sm">
                    @foreach (['90' => 'Over 90 days', '180' => 'Over 180 days', '365' => 'Over 365 days'] as $k => $label)
                        <div class="dl-row"><dt>{{ $label }}</dt><dd>{{ amount($a['over'][$k], false) }} <span class="text-xs text-ink-500">{{ $a['total'] ? round($a['over'][$k] * 100 / $a['total'], 1) : 0 }}%</span></dd></div>
                    @endforeach
                    <p class="pt-2 text-xs text-ink-500">Based on the "from (days)" set for each column. {{ $report->type === 'receivables' ? 'Use these when reviewing the expected credit loss allowance.' : 'Long-outstanding payables may indicate disputes or unrecorded settlements.' }}</p>
                </dl>
            </div>
            <div class="card card-body text-xs text-ink-600">To sample from this listing (e.g. for confirmations), open a draft sampling plan and choose <span class="font-medium">Load from ageing</span>. Positive balances become the population.</div>
        </div>
    </div>
</x-layouts.app>
