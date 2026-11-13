<x-layouts.app :title="'Audit differences · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-fieldwork-nav :engagement="$engagement" />
    @php $fl = $sad['flags']; $rm = fn ($v) => $v === null ? '—' : amount($v); @endphp
    <div class="space-y-6">
        <div class="card card-body text-sm text-ink-600">
            <p>Summary of misstatements accumulated during the audit, other than those that are clearly trivial. Amounts are recorded minus audited: positive figures are overstatements. Whether uncorrected misstatements are material, individually or in aggregate, and their effect on the opinion, is the engagement partner's judgement — the comparisons below inform it.</p>
        </div>

        @if ($fl['no_materiality'])
            <p class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Materiality has not been approved, so misstatements cannot be compared with it and no clearly trivial threshold applies — every misstatement is listed.</p>
        @endif

        <div class="grid gap-4 sm:grid-cols-4">
            <x-stat label="Overall materiality" :value="'RM '.$rm($sad['om_sen'])" />
            <x-stat label="Performance materiality" :value="'RM '.$rm($sad['pm_sen'])" />
            <x-stat label="Uncorrected — net" :value="'RM '.$rm($sad['net_sen'])" :hint="'Gross (ignoring direction): RM '.$rm($sad['gross_sen'])" />
            <x-stat label="Clearly trivial threshold" :value="'RM '.$rm($sad['ct_sen'])" :hint="$sad['trivial']->count().' item(s) below it, RM '.$rm($sad['trivial_total_sen'])" />
        </div>

        <div class="space-y-2">
            @if ($fl['individual_at_or_above_om'])<p class="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">An individual uncorrected misstatement is at or above overall materiality.</p>@endif
            @if ($fl['aggregate_at_or_above_om'])<p class="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">Uncorrected misstatements in aggregate are at or above overall materiality.</p>
            @elseif ($fl['aggregate_at_or_above_pm'])<p class="rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900">Uncorrected misstatements in aggregate are at or above performance materiality. Consider whether the audit strategy and plan, or further procedures, need revisiting, and request correction.</p>@endif
            @if ($fl['fsli_at_or_above_limit'])<p class="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">Uncorrected misstatements in at least one line item are at or above the materiality applicable to it.</p>@endif
            @if ($fl['awaiting_management'])<p class="rounded-md border border-navy-100 bg-navy-50 px-4 py-2 text-sm text-ink-700">{{ $fl['awaiting_management'] }} misstatement(s) proposed to management with no response recorded yet.</p>@endif
        </div>

        <div class="card overflow-x-auto">
            <div class="card-header"><h2 class="card-title">Uncorrected misstatements by line item</h2></div>
            @if ($sad['by_fsli']->isEmpty())
                <x-empty title="No uncorrected misstatements" description="Misstatements recorded as findings appear here until management corrects them." />
            @else
                <table class="table text-sm">
                    <thead><tr><th>Line item</th><th class="text-right">Factual</th><th class="text-right">Judgemental</th><th class="text-right">Projected</th><th class="text-right">Net</th><th class="text-right">Materiality applied</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($sad['by_fsli'] as $r)
                        <tr>
                            <td>{{ $r['fsli']?->name ?? '—' }}</td>
                            <td class="text-right tabular-nums">{{ amount($r['factual_sen']) }}</td>
                            <td class="text-right tabular-nums">{{ amount($r['judgemental_sen']) }}</td>
                            <td class="text-right tabular-nums">{{ amount($r['projected_sen']) }}</td>
                            <td class="text-right font-semibold tabular-nums">{{ amount($r['net_sen']) }}</td>
                            <td class="text-right tabular-nums">{{ $rm($r['limit_sen']) }} <span class="text-2xs text-ink-500">{{ $r['limit_is_specific'] ? 'specific' : 'overall' }}</span></td>
                            <td>@if ($r['at_or_above_limit'])<span class="badge badge-danger">At or above</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr class="border-t-2 border-navy-100 font-semibold">
                        <td class="px-4 py-3">Total</td>
                        @foreach (['factual', 'judgemental', 'projected'] as $c)<td class="px-4 py-3 text-right tabular-nums">{{ amount($sad['by_class'][$c]) }}</td>@endforeach
                        <td class="px-4 py-3 text-right tabular-nums">{{ amount($sad['net_sen']) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ $rm($sad['om_sen']) }}</td><td></td></tr></tfoot>
                </table>
            @endif
        </div>

        @foreach (['uncorrected' => 'Uncorrected misstatements', 'corrected' => 'Corrected misstatements', 'trivial' => 'Clearly trivial (not accumulated)'] as $k => $title)
            @continue($sad[$k]->isEmpty())
            <div class="card overflow-x-auto">
                <div class="card-header"><h2 class="card-title">{{ $title }}</h2></div>
                <table class="table text-sm">
                    <thead><tr><th>Ref</th><th>Finding</th><th>Line item</th><th>Class</th><th class="text-right">Amount (RM)</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach ($sad[$k] as $f)
                        <tr class="row-link">
                            <td class="font-mono text-xs font-semibold">{{ $f->reference }}</td>
                            <td><a href="{{ route('findings.show', [$engagement, $f]) }}" class="text-navy-900 hover:text-teal-700">{{ $f->title }}</a>@if($f->uncorrected_reason)<div class="text-xs text-ink-500">Reason: {{ \Illuminate\Support\Str::limit($f->uncorrected_reason, 100) }}</div>@endif</td>
                            <td class="text-xs">{{ $f->fsli?->name }}</td>
                            <td class="text-xs">{{ $f->misstatement_class->label() }}</td>
                            <td class="text-right tabular-nums">{{ amount($f->amount_sen) }}</td>
                            <td><x-status :value="$f->correction_status" />@if($f->adjustment) <span class="text-xs">{{ $f->adjustment->number }}</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
</x-layouts.app>
