<x-layouts.app :title="'Financial statements · '.$engagement->code">
    @unless ($print)
        <x-engagement-nav :engagement="$engagement" />
        <x-completion-nav :engagement="$engagement" />
    @endunless
    @php $rm = fn ($v) => $v < 0 ? '('.amount(-$v, false).')' : amount($v, false); @endphp
    <div class="space-y-6">
        <div class="card card-body flex flex-wrap items-center justify-between gap-3 text-sm">
            <div>
                <div class="font-semibold text-navy-900">{{ $engagement->client->name }}</div>
                <div class="text-ink-500">Primary statements for the period {{ $engagement->periodLabel() }} · {{ $engagement->reporting_framework->label() }} · RM</div>
            </div>
            <div class="flex items-center gap-2">
                @if ($snapshot)<span class="badge badge-success">As signed {{ local_time(\Illuminate\Support\Carbon::parse($fs['generated_at'])) }}</span>
                @else<span class="badge badge-warn">Draft — from the adjusted trial balance</span>@endif
                @unless ($print)<a href="{{ route('reporting.statements', [$engagement, 'print' => 1]) }}" target="_blank" class="btn-secondary btn-sm">Print view</a>@endunless
            </div>
        </div>
        @if (! $fs['balanced'])
            <p class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">The statements do not balance or accounts are unmapped (difference RM {{ amount($fs['checks']['sofp_difference'], false) }}, {{ $fs['checks']['unmapped_count'] }} unmapped account(s)). Resolve this in the trial balance before reporting.</p>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="card overflow-x-auto">
                <div class="card-header"><h2 class="card-title">Statement of financial position</h2></div>
                <table class="table text-sm">
                    <thead><tr><th></th><th class="text-right">Current year</th><th class="text-right">Prior year</th></tr></thead>
                    <tbody>
                    @foreach ($fs['sofp'] as $sec)
                        @continue(empty($sec['lines']))
                        <tr><td colspan="3" class="bg-navy-50/50 text-2xs font-semibold uppercase tracking-wider text-ink-600">{{ $sec['label'] }}</td></tr>
                        @foreach ($sec['lines'] as $l)<tr><td>{{ $l['name'] }}</td><td class="text-right tabular-nums">{{ $rm($l['cy']) }}</td><td class="text-right tabular-nums text-ink-500">{{ $rm($l['py']) }}</td></tr>@endforeach
                        <tr class="font-semibold"><td>Total {{ mb_strtolower($sec['label']) }}</td><td class="text-right tabular-nums">{{ $rm($sec['cy']) }}</td><td class="text-right tabular-nums text-ink-500">{{ $rm($sec['py']) }}</td></tr>
                    @endforeach
                    <tr class="font-semibold"><td>Profit for the year (to retained earnings)</td><td class="text-right tabular-nums">{{ $rm($fs['cy']['profit']) }}</td><td class="text-right tabular-nums text-ink-500">{{ $rm($fs['py']['profit'] ?? 0) }}</td></tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-navy-100 font-semibold"><td class="px-4 py-2">Total assets</td><td class="px-4 py-2 text-right tabular-nums">{{ $rm($fs['cy']['assets']) }}</td><td class="px-4 py-2 text-right tabular-nums text-ink-500">{{ $rm($fs['py']['assets'] ?? 0) }}</td></tr>
                        <tr class="font-semibold"><td class="px-4 py-2">Total equity and liabilities</td><td class="px-4 py-2 text-right tabular-nums">{{ $rm($fs['cy']['equity'] + $fs['cy']['liabilities'] + $fs['cy']['profit']) }}</td><td class="px-4 py-2 text-right tabular-nums text-ink-500">{{ $rm(($fs['py']['equity'] ?? 0) + ($fs['py']['liabilities'] ?? 0) + ($fs['py']['profit'] ?? 0)) }}</td></tr>
                    </tfoot>
                </table>
            </div>
            <div class="card overflow-x-auto">
                <div class="card-header"><h2 class="card-title">Statement of profit or loss</h2></div>
                <table class="table text-sm">
                    <thead><tr><th></th><th class="text-right">Current year</th><th class="text-right">Prior year</th></tr></thead>
                    <tbody>
                    @foreach ($fs['sopl'] as $sec)
                        @foreach ($sec['lines'] as $l)<tr><td>{{ $l['name'] }}</td><td class="text-right tabular-nums">{{ $rm($sec['sign'] * $l['cy']) }}</td><td class="text-right tabular-nums text-ink-500">{{ $rm($sec['sign'] * $l['py']) }}</td></tr>@endforeach
                        @if ($sec['label'] === 'Cost of sales')<tr class="font-semibold"><td>Gross profit</td><td class="text-right tabular-nums">{{ $rm($fs['cy']['gross_profit']) }}</td><td class="text-right tabular-nums text-ink-500">{{ $rm($fs['py']['gross_profit'] ?? 0) }}</td></tr>@endif
                        @if ($sec['label'] === 'Finance costs')<tr class="font-semibold"><td>Profit before tax</td><td class="text-right tabular-nums">{{ $rm($fs['cy']['pbt']) }}</td><td class="text-right tabular-nums text-ink-500">{{ $rm($fs['py']['pbt'] ?? 0) }}</td></tr>@endif
                    @endforeach
                    </tbody>
                    <tfoot><tr class="border-t-2 border-navy-100 font-semibold"><td class="px-4 py-2">Profit for the year</td><td class="px-4 py-2 text-right tabular-nums">{{ $rm($fs['cy']['profit']) }}</td><td class="px-4 py-2 text-right tabular-nums text-ink-500">{{ $rm($fs['py']['profit'] ?? 0) }}</td></tr></tfoot>
                </table>
            </div>
        </div>
        <p class="text-xs text-ink-500">Presentation follows the FSLI mapping. Notes, accounting policies, the statements of changes in equity and cash flows, and framework-specific presentation are prepared by the engagement team.</p>
    </div>
</x-layouts.app>
