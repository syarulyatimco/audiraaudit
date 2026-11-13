@php $out = $run->output ?? []; $saved = $engagement->messages()->where('ai_run_id', $run->id)->exists(); @endphp
<div class="card">
    <div class="card-header"><h3 class="card-title">AI draft message</h3><span class="text-xs text-ink-500">Not sent</span></div>
    @include('ai.partials.guard-note')
    <div class="card-body space-y-2 text-sm">
        <div class="font-medium text-navy-900">{{ $out['subject'] ?? '' }}</div>
        <p class="whitespace-pre-line text-ink-700">{{ $out['body'] ?? '' }}</p>
    </div>
    @if (! $saved && \App\Support\Authorization\Permissions::allowsOnEngagement(auth()->user(), $engagement, 'engagement.message_client'))
        <form method="POST" action="{{ route('ai-runs.accept-message', [$engagement, $run]) }}" class="flex justify-end border-t border-navy-100/70 px-5 py-3">@csrf<button class="btn-accent btn-sm">Save to drafts</button></form>
    @elseif ($saved)
        <p class="border-t border-navy-100/70 px-5 py-3 text-xs text-ink-500">Saved to your drafts below.</p>
    @endif
</div>
