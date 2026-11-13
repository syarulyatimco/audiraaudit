@php $out = $run->output ?? []; $fslis = $engagement->fslis()->get()->keyBy('code'); @endphp
<div class="card">
    <div class="card-header"><div><h3 class="card-title">AI analytical observations</h3>@if (! empty($out['summary']))<p class="text-xs text-ink-500">{{ $out['summary'] }}</p>@endif</div>
        <span class="text-xs text-ink-500">For the team's consideration — not conclusions</span></div>
    <ul class="divide-y divide-navy-50">
        @forelse ($out['observations'] ?? [] as $o)
            <li class="px-5 py-3 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="badge {{ ['high' => 'badge-danger', 'medium' => 'badge-warn', 'low' => 'badge-neutral'][$o['severity']] }}">{{ $o['severity'] }}</span>
                    <span class="badge badge-neutral">{{ str_replace('_', ' ', $o['category']) }}</span>
                    @if ($o['fsli_code'] && isset($fslis[$o['fsli_code']]))<a href="{{ route('lead-schedules.show', [$engagement, $fslis[$o['fsli_code']]]) }}" class="text-xs font-medium text-teal-700">{{ $fslis[$o['fsli_code']]->name }}</a>@endif
                    @if (! empty($o['unverified_figures']))<span class="badge badge-danger" title="These figures were not in AUDIRA's data — verify before relying on them">Unverified figures: {{ implode(', ', $o['unverified_figures']) }}</span>@endif
                </div>
                <p class="mt-1 text-ink-900">{{ $o['observation'] }}</p>
                <p class="mt-1 text-xs text-ink-600"><span class="font-semibold">Suggested procedure:</span> {{ $o['suggested_procedure'] }}</p>
            </li>
        @empty
            <li class="px-5 py-4 text-sm text-ink-500">No observations.</li>
        @endforelse
    </ul>
</div>
