@props(['engagement'])
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div class="inline-flex rounded-md border border-navy-100 bg-white p-0.5 text-sm">
        @foreach ([['trial-balance.index', 'Imports', 'trial-balance.index|trial-balance.configure'], ['trial-balance.mapping', 'Mapping', 'trial-balance.mapping'], ['lead-schedules.index', 'Lead schedules', 'lead-schedules.*'], ['adjustments.index', 'Adjustments', 'adjustments.*']] as [$r, $l, $p])
            <a href="{{ route($r, $engagement) }}" class="rounded px-3 py-1.5 font-medium {{ request()->routeIs(...explode('|', $p)) ? 'bg-navy-800 text-white' : 'text-ink-600 hover:text-navy-900' }}">{{ $l }}</a>
        @endforeach
    </div>
    <a href="{{ route('trial-balance.export', $engagement) }}" class="btn-secondary btn-sm">Export adjusted TB (CSV)</a>
</div>
