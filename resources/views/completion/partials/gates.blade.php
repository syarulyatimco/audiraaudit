{{-- $gates: key => [ok, detail] --}}
<ul class="space-y-1 text-sm">
    @foreach ($gates as $g)
        <li class="flex items-start gap-2"><span class="mt-0.5 {{ $g['ok'] ? 'text-emerald-600' : 'text-red-600' }}">{{ $g['ok'] ? '✓' : '✕' }}</span><span class="{{ $g['ok'] ? 'text-ink-600' : 'text-ink-800' }}">{{ $g['detail'] }}</span></li>
    @endforeach
</ul>
