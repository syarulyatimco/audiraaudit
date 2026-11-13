@php $f = $schedule['fsli']; @endphp
<x-layouts.app :title="'Lead schedule · '.$f->name">
    <x-engagement-nav :engagement="$engagement" />
    <x-tb-nav :engagement="$engagement" />
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div><a href="{{ route('lead-schedules.index', $engagement) }}" class="text-sm text-ink-500 hover:text-navy-900">← Lead schedules</a>
            <h2 class="mt-1 text-xl font-semibold">{{ $f->name }} <span class="font-mono text-sm text-ink-400">{{ $f->code }}</span></h2>
            <p class="text-sm text-ink-500">{{ $f->classification->label() }} · amounts shown on the {{ $f->classification->sign() > 0 ? 'debit' : 'credit' }} side as positive</p></div>
        @if ($f->workingPaper)
            <a href="{{ route('working-papers.show', [$engagement, $f->workingPaper]) }}" class="btn-secondary">Open {{ $f->workingPaper->reference }}</a>
        @elseif ($canCreatePaper)
            <form method="POST" action="{{ route('lead-schedules.paper', [$engagement, $f]) }}">@csrf<button class="btn-primary">Create lead schedule working paper</button></form>
        @endif
    </div>
    <div class="card overflow-x-auto">
        <table class="table text-sm">
            <thead><tr><th>Account</th><th class="text-right">Prior year</th><th class="text-right">Unadjusted</th><th class="text-right">Adjustments</th><th class="text-right">Adjusted</th><th class="text-right">Change</th></tr></thead>
            <tbody>
            @php $s = $f->classification->sign(); @endphp
            @forelse ($schedule['accounts'] as $a)
                <tr class="{{ in_array($a['code'], $schedule['contra'], true) ? 'bg-amber-50/50' : '' }}">
                    <td><span class="font-mono text-xs font-semibold">{{ $a['code'] }}</span> {{ $a['name'] }}@if (in_array($a['code'], $schedule['contra'], true))<span class="badge badge-neutral ml-1" title="Balance on the opposite side to the FSLI (e.g. accumulated depreciation, allowances, overdrafts)">Opposite balance</span>@endif</td>
                    <td class="text-right tabular-nums text-ink-500">{{ amount($s * $a['py']) }}</td>
                    <td class="text-right tabular-nums">{{ amount($s * $a['cy']) }}</td>
                    <td class="text-right tabular-nums text-teal-700">{{ amount($s * $a['adj']) }}</td>
                    <td class="text-right font-medium tabular-nums">{{ amount($s * $a['cy_adj']) }}</td>
                    <td class="text-right tabular-nums text-ink-500">{{ amount($s * ($a['cy_adj'] - $a['py'])) }}</td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty title="No accounts mapped" description="Map trial balance accounts to this FSLI on the Mapping page." /></td></tr>
            @endforelse
            </tbody>
            <tfoot><tr class="border-t-2 border-navy-100 font-semibold">
                <td class="px-4 py-3">Total</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ amount($schedule['py'], false) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ amount($schedule['cy'], false) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ amount($schedule['adj'], false) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ amount($schedule['cy_adj'], false) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ amount($schedule['cy_adj'] - $schedule['py'], false) }}</td>
            </tr></tfoot>
        </table>
    </div>
</x-layouts.app>
