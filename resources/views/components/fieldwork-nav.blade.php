@props(['engagement'])
<div class="mb-6 inline-flex rounded-md border border-navy-100 bg-white p-0.5 text-sm">
    @foreach ([['programme.index', 'Audit programme', 'programme.*'], ['sampling.index', 'Sampling', 'sampling.*'], ['findings.index', 'Findings', 'findings.index|findings.show|findings.create|findings.edit'], ['findings.differences', 'Audit differences', 'findings.differences']] as [$r, $l, $p])
        <a href="{{ route($r, $engagement) }}" class="rounded px-3 py-1.5 font-medium {{ request()->routeIs(...explode('|', $p)) ? 'bg-navy-800 text-white' : 'text-ink-600 hover:text-navy-900' }}">{{ $l }}</a>
    @endforeach
</div>
