<x-layouts.app :title="'Trial balance · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-tb-nav :engagement="$engagement" />

    @php $c = $summary['checks']; @endphp
    @if ($summary['has_current'])
        <div class="mb-6 grid gap-4 sm:grid-cols-4">
            <x-stat label="TB balances" :value="$c['tb_balances'] ? 'Yes' : 'No'" hint="Adjusted current year" />
            <x-stat label="Unmapped accounts" :value="$c['unmapped_count']" :hint="$c['unmapped_count'] ? 'Net '.amount($c['unmapped_net']) : 'All mapped'" :href="route('trial-balance.mapping', [$engagement, 'show' => 'unmapped'])" />
            <x-stat label="Profit / (loss) before tax" :value="amount($summary['cy']['pbt'], false)" hint="Adjusted, mapped accounts" :href="route('lead-schedules.index', $engagement)" />
            <x-stat label="Total assets" :value="amount($summary['cy']['assets'], false)" :href="route('lead-schedules.index', $engagement)" />
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @foreach (\App\Enums\TbPeriod::cases() as $period)
                @php $imp = $active->get($period->value); @endphp
                <div class="card">
                    <div class="card-header"><h2 class="card-title">{{ $period->label() }}</h2>@if($imp)<span class="badge badge-success badge-dot">Active</span>@else<span class="badge badge-neutral">Not imported</span>@endif</div>
                    @if ($imp)
                        <dl class="card-body grid gap-x-8 sm:grid-cols-2">
                            <div class="dl-row"><dt>Accounts</dt><dd class="tabular-nums">{{ number_format($imp->row_count) }}</dd></div>
                            <div class="dl-row"><dt>Total debits = credits</dt><dd class="tabular-nums">{{ amount($imp->total_debit_sen, false) }}</dd></div>
                            <div class="dl-row"><dt>Imported by</dt><dd>{{ $imp->importer->name }}</dd></div>
                            <div class="dl-row"><dt>Activated</dt><dd>{{ local_time($imp->activated_at) }}</dd></div>
                            <div class="dl-row sm:col-span-2"><dt>Source file</dt><dd><a href="{{ route('documents.show', [$engagement, $imp->document]) }}" class="text-teal-700 hover:text-teal-800">{{ $imp->document->title }}</a></dd></div>
                        </dl>
                        @if ($imp->warnings)
                            <details class="border-t border-navy-50 px-5 py-3 text-xs text-amber-800"><summary class="cursor-pointer">{{ count($imp->warnings) }} import warning(s)</summary>
                                <ul class="mt-2 list-inside list-disc space-y-0.5">@foreach ($imp->warnings as $w)<li>{{ $w }}</li>@endforeach</ul></details>
                        @endif
                    @else
                        <p class="card-body text-sm text-ink-500">{{ $period === \App\Enums\TbPeriod::Current ? 'Import the client\'s trial balance as at '.$engagement->period_end->format('j M Y').'.' : 'Optional: import the prior-year trial balance for comparatives and analytics.' }}</p>
                    @endif
                </div>
            @endforeach

            @if ($pending->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Awaiting column mapping</h2></div>
                    <ul class="divide-y divide-navy-50">
                        @foreach ($pending as $p)
                            <li class="flex items-center justify-between px-5 py-3 text-sm"><span>{{ $p->period->label() }} · {{ $p->document->title }} · {{ local_time($p->created_at) }}</span>
                                <a href="{{ route('trial-balance.configure', [$engagement, $p]) }}" class="btn-secondary btn-sm">Continue</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($history->isNotEmpty())
                <details class="card">
                    <summary class="card-header cursor-pointer"><span class="card-title">Import history ({{ $history->count() }})</span></summary>
                    <ul class="divide-y divide-navy-50 text-sm">
                        @foreach ($history as $h)
                            <li class="flex justify-between px-5 py-2.5"><span>{{ $h->period->label() }} · {{ $h->importer->name }} · {{ local_time($h->created_at) }}</span><span class="badge badge-neutral">{{ $h->status->label() }}</span></li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>

        @if ($canImport)
            <form method="POST" action="{{ route('trial-balance.upload', $engagement) }}" enctype="multipart/form-data" class="card h-max">
                @csrf
                <div class="card-header"><h2 class="card-title">Import trial balance</h2></div>
                <div class="card-body space-y-4">
                    <x-select name="period" label="Period" :options="\App\Enums\TbPeriod::options()" value="current" required />
                    <div>
                        <label class="label" for="file">File <span class="text-red-500">*</span></label>
                        <input id="file" type="file" name="file" accept=".csv,.xlsx" required class="block w-full text-sm text-ink-600 file:mr-3 file:rounded-md file:border-0 file:bg-navy-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-navy-800">
                        <p class="hint">CSV or XLSX (first worksheet). Exports from SQL Accounting, AutoCount, Xero, QuickBooks and similar work if they have account code, name and debit/credit or balance columns.</p>
                        @error('file')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <p class="text-xs text-ink-500">The file is kept as evidence in Documents. A new import replaces the active TB for that period; mappings and adjustments are kept.</p>
                    <button class="btn-primary w-full">Upload and review</button>
                </div>
            </form>
        @endif
    </div>
</x-layouts.app>
