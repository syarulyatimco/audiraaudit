<x-layouts.app :title="'Lead schedules · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-tb-nav :engagement="$engagement" />
    @php
        $c = $data['checks'];
        $rows = fn ($classes) => $data['fslis']->filter(fn ($s) => in_array($s['fsli']->classification, $classes, true) && ($s['accounts']->isNotEmpty()));
        $C = \App\Enums\FsliClassification::class;
        $sections = [
            'Statement of financial position' => [
                [[$C::NonCurrentAsset], 'Non-current assets'], [[$C::CurrentAsset], 'Current assets'],
                [[$C::Equity], 'Equity'], [[$C::NonCurrentLiability], 'Non-current liabilities'], [[$C::CurrentLiability], 'Current liabilities'],
            ],
            'Statement of profit or loss' => [
                [[$C::Revenue], 'Revenue'], [[$C::CostOfSales], 'Cost of sales'], [[$C::OtherIncome], 'Other income'],
                [[$C::Expense], 'Operating expenses'], [[$C::FinanceCost], 'Finance costs'], [[$C::TaxExpense], 'Income tax expense'],
            ],
        ];
    @endphp

    @if (! $data['has_current'])
        <div class="card"><x-empty title="No current-year trial balance" description="Import a trial balance to build lead schedules."><a href="{{ route('trial-balance.index', $engagement) }}" class="btn-primary btn-sm">Import</a></x-empty></div>
    @else
        <div class="mb-6">@include('ai.partials.panel', ['task' => 'review_tb', 'title' => 'AI analytical review', 'blurb' => 'The Accounting & TB agent comments on AUDIRA-computed changes, opposite balances and relationships, and suggests procedures. Figures it quotes are checked against AUDIRA data.', 'show' => true])</div>
        <div class="mb-6 flex flex-wrap gap-3 text-sm">
            <span class="badge {{ $c['tb_balances'] ? 'badge-success' : 'badge-danger' }} badge-dot">{{ $c['tb_balances'] ? 'Adjusted TB balances' : 'Adjusted TB does not balance' }}</span>
            @if ($c['unmapped_count'])
                <a href="{{ route('trial-balance.mapping', [$engagement, 'show' => 'unmapped']) }}" class="badge badge-warn badge-dot">{{ $c['unmapped_count'] }} unmapped account(s), net {{ amount($c['unmapped_net'], false) }} — statements are incomplete</a>
            @else
                <span class="badge {{ $c['sofp_difference'] === 0 ? 'badge-success' : 'badge-danger' }} badge-dot">Assets = liabilities + equity + profit {{ $c['sofp_difference'] === 0 ? '✓' : '(difference '.amount($c['sofp_difference'], false).')' }}</span>
            @endif
        </div>

        @foreach ($sections as $title => $groups)
            <div class="card mb-6 overflow-x-auto">
                <div class="card-header"><h2 class="card-title">{{ $title }}</h2><span class="text-xs text-ink-500">RM · as at / for the period ended {{ $engagement->period_end->format('j M Y') }}</span></div>
                <table class="table text-sm">
                    <thead><tr><th>FSLI</th><th class="text-right">Prior year</th><th class="text-right">Unadjusted</th><th class="text-right">Adjustments</th><th class="text-right">Adjusted</th><th class="text-right">Change</th><th>Lead paper</th></tr></thead>
                    <tbody>
                    @foreach ($groups as [$classes, $label])
                        @php $set = $rows($classes); @endphp
                        @continue($set->isEmpty())
                        <tr class="bg-navy-50/40"><td colspan="7" class="!py-1.5 text-2xs font-semibold uppercase tracking-wider text-ink-500">{{ $label }}</td></tr>
                        @foreach ($set as $s)
                            @php $chg = $s['py'] !== 0 ? ($s['cy_adj'] - $s['py']) / abs($s['py']) * 100 : null; @endphp
                            <tr class="row-link">
                                <td><a href="{{ route('lead-schedules.show', [$engagement, $s['fsli']]) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $s['fsli']->name }}</a>
                                    @if ($s['opposite'])<span class="badge badge-danger ml-1" title="The FSLI's net balance is on the opposite side to its classification — review presentation">Net opposite balance</span>@endif</td>
                                <td class="text-right tabular-nums text-ink-500">{{ amount($s['py']) }}</td>
                                <td class="text-right tabular-nums">{{ amount($s['cy']) }}</td>
                                <td class="text-right tabular-nums {{ $s['adj'] ? 'text-teal-700' : '' }}">{{ amount($s['adj']) }}</td>
                                <td class="text-right font-semibold tabular-nums">{{ amount($s['cy_adj']) }}</td>
                                <td class="text-right text-xs tabular-nums {{ $chg !== null && abs($chg) >= 20 ? 'font-semibold text-amber-700' : 'text-ink-500' }}">{{ $chg === null ? '–' : number_format($chg, 1).'%' }}</td>
                                <td>@if ($s['fsli']->workingPaper)<a href="{{ route('working-papers.show', [$engagement, $s['fsli']->workingPaper]) }}" class="font-mono text-xs text-teal-700">{{ $s['fsli']->workingPaper->reference }}</a> <x-status :value="$s['fsli']->workingPaper->status" />@endif</td>
                            </tr>
                        @endforeach
                    @endforeach
                    @php $k = $title === 'Statement of profit or loss'; @endphp
                    <tr class="border-t-2 border-navy-100 font-semibold">
                        <td>{{ $k ? 'Profit / (loss) for the year' : 'Total assets' }}</td>
                        <td class="text-right tabular-nums">{{ amount($k ? $data['py']['profit'] : $data['py']['assets'], false) }}</td>
                        <td class="text-right tabular-nums">{{ amount($k ? $data['cy_unadjusted']['profit'] : $data['cy_unadjusted']['assets'], false) }}</td>
                        <td></td>
                        <td class="text-right tabular-nums">{{ amount($k ? $data['cy']['profit'] : $data['cy']['assets'], false) }}</td>
                        <td colspan="2"></td>
                    </tr>
                    </tbody>
                </table>
            </div>
        @endforeach
        <p class="text-xs text-ink-500">Figures are working drafts built from the trial balance and posted adjustments. Percentage changes of 20% or more are highlighted for analytical review; this is a display aid, not a materiality threshold.</p>
    @endif
</x-layouts.app>
