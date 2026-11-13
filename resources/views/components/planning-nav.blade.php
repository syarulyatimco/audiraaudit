@props(['engagement'])
<div class="mb-6 inline-flex rounded-md border border-navy-100 bg-white p-0.5 text-sm">
    @foreach ([['strategy.show', 'Audit strategy', 'strategy.*'], ['materiality.index', 'Materiality', 'materiality.*'], ['risks.index', 'Risk assessment', 'risks.*']] as [$r, $l, $p])
        <a href="{{ route($r, $engagement) }}" class="rounded px-3 py-1.5 font-medium {{ request()->routeIs($p) ? 'bg-navy-800 text-white' : 'text-ink-600 hover:text-navy-900' }}">{{ $l }}</a>
    @endforeach
</div>
