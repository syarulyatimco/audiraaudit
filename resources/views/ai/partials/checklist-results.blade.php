@php
    $out = $run->output ?? [];
    $canAccept = ! $engagement->status->isLocked() && \App\Support\Authorization\Permissions::allowsOnEngagement(auth()->user(), $engagement, 'engagement.manage_completion');
    $items = $engagement->completionItems()->get()->keyBy('key');
@endphp
<form method="POST" action="{{ route('ai-runs.accept-checklist', [$engagement, $run]) }}" class="card">
    @csrf
    <div class="card-header"><div><h3 class="card-title">AI-drafted checklist responses ({{ count($out['responses'] ?? []) }})</h3><p class="text-xs text-ink-500">{{ $out['summary'] ?? '' }}</p></div>
        @if ($canAccept && ! empty($out['responses']))<button class="btn-accent btn-sm">Save selected as drafts</button>@endif</div>
    @include('ai.partials.guard-note')
    <ul class="divide-y divide-navy-50">
        @forelse ($out['responses'] ?? [] as $i => $r)
            @php $item = $items[$r['key']] ?? null; $open = $item && $item->status->value === 'open'; @endphp
            <li class="flex gap-3 px-5 py-3 text-sm">
                @if ($canAccept && $open)<input type="checkbox" name="selected[]" value="{{ $i }}" class="checkbox mt-1" checked>@endif
                <div class="flex-1"><div class="font-medium text-navy-900">{{ $item?->title ?? $r['key'] }}@if(! $open) <span class="badge badge-neutral">Answered</span>@endif</div>
                    <p class="mt-1 whitespace-pre-line text-ink-700">{{ $r['response'] }}</p></div>
            </li>
        @empty
            <li class="px-5 py-4 text-sm text-ink-500">No responses drafted.</li>
        @endforelse
    </ul>
</form>
