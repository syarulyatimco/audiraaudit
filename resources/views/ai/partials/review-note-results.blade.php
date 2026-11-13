@php
    $out = $run->output ?? [];
    $canAccept = ! $engagement->status->isLocked() && \App\Support\Authorization\Permissions::allowsOnEngagement(auth()->user(), $engagement, 'engagement.raise_review_notes');
@endphp
<form method="POST" action="{{ route('ai-runs.accept-review-notes', [$engagement, $run]) }}" class="card">
    @csrf
    <div class="card-header"><div><h3 class="card-title">AI pre-review ({{ count($out['notes'] ?? []) }} notes)</h3><p class="text-xs text-ink-500">{{ $out['summary'] ?? '' }}</p></div>
        @if ($canAccept && ! empty($out['notes']))<button class="btn-accent btn-sm">Raise selected notes</button>@endif</div>
    @include('ai.partials.guard-note')
    <ul class="divide-y divide-navy-50">
        @forelse ($out['notes'] ?? [] as $i => $n)
            <li class="flex gap-3 px-5 py-3 text-sm">
                @if ($canAccept)<input type="checkbox" name="selected[]" value="{{ $i }}" class="checkbox mt-1">@endif
                <div><span class="font-mono text-xs font-semibold">{{ $n['working_paper'] }}</span> <span class="text-ink-700">{{ $n['note'] }}</span></div>
            </li>
        @empty
            <li class="px-5 py-4 text-sm text-ink-500">No gaps found on working papers.</li>
        @endforelse
    </ul>
    @if (! empty($out['file_observations']))<ul class="space-y-1 border-t border-navy-50 px-5 py-3 text-xs text-ink-600">@foreach ($out['file_observations'] as $o)<li>• {{ $o }}</li>@endforeach</ul>@endif
</form>
