@php
    $out = $run->output ?? [];
    $canAccept = ! $engagement->status->isLocked() && \App\Support\Authorization\Permissions::allowsOnEngagement(auth()->user(), $engagement, 'engagement.manage_programme');
@endphp
<form method="POST" action="{{ route('ai-runs.accept-rows', [$engagement, $run]) }}" class="card">
    @csrf
    <div class="card-header"><div><h3 class="card-title">AI-suggested steps ({{ count($out['rows'] ?? []) }})</h3><p class="text-xs text-ink-500">{{ $out['summary'] ?? '' }}</p></div>
        @if ($canAccept && ! empty($out['rows']))<button class="btn-accent btn-sm">Add selected rows</button>@endif</div>
    @include('ai.partials.guard-note')
    <ul class="divide-y divide-navy-50">
        @forelse ($out['rows'] ?? [] as $i => $r)
            <li class="flex gap-3 px-5 py-3 text-sm">
                @if ($canAccept)<input type="checkbox" name="selected[]" value="{{ $i }}" class="checkbox mt-1" checked>@endif
                <div class="flex-1 space-y-1">
                    <p class="text-ink-800">{{ $r['procedure'] }}</p>
                    <p class="text-xs text-ink-500">Responds to {{ implode(', ', $r['risk_references']) }} · {{ $r['assertions'] ?: 'no assertion' }}{{ $r['isa_refs'] ? ' · '.$r['isa_refs'] : '' }}</p>
                    <p class="text-xs italic text-ink-500">{{ $r['rationale'] }}</p>
                </div>
            </li>
        @empty
            <li class="px-5 py-4 text-sm text-ink-500">No additional steps proposed.</li>
        @endforelse
    </ul>
</form>
