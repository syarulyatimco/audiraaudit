{{-- Sign-off panel: $wp, $engagement, $canPrepare, $canReview --}}
@php
    $active = $wp->signoffs->whereNull('invalidated_at');
    $preparer = $active->firstWhere('role', 'preparer');
    $reviewer = $active->firstWhere('role', 'reviewer');
    $history = $wp->signoffs->whereNotNull('invalidated_at');
@endphp
<div class="card">
    <div class="card-header"><h2 class="card-title">Sign-off</h2><x-status :value="$wp->status" /></div>
    <div class="card-body space-y-3">
        @foreach (['preparer' => [$preparer, 'Prepared by'], 'reviewer' => [$reviewer, 'Reviewed by']] as $role => [$s, $label])
            <div class="flex items-start justify-between gap-3 rounded-md border border-navy-50 px-3 py-2.5">
                <div>
                    <div class="text-2xs font-semibold uppercase tracking-wider text-ink-500">{{ $label }}</div>
                    @if ($s)
                        <div class="text-sm font-medium text-navy-900">{{ $s->user->name }}</div>
                        <div class="text-xs text-ink-500">{{ local_time($s->signed_at) }}</div>
                    @else
                        <div class="text-sm text-ink-400">Not signed</div>
                    @endif
                </div>
                @if (! $s)
                    @if ($role === 'preparer' && $canPrepare && $wp->status === \App\Enums\WorkingPaperStatus::Draft)
                        <form method="POST" action="{{ route('working-papers.signoff', [$engagement, $wp]) }}">@csrf<input type="hidden" name="role" value="preparer"><button class="btn-accent btn-sm">Sign as preparer</button></form>
                    @elseif ($role === 'reviewer' && $canReview && $wp->status === \App\Enums\WorkingPaperStatus::Prepared && $preparer?->user_id !== auth()->id())
                        <form method="POST" action="{{ route('working-papers.signoff', [$engagement, $wp]) }}">@csrf<input type="hidden" name="role" value="reviewer"><button class="btn-accent btn-sm">Sign as reviewer</button></form>
                    @endif
                @endif
            </div>
        @endforeach
        @if ($wp->content_hash)
            <p class="break-all font-mono text-2xs text-ink-400" title="SHA-256 of the content each sign-off attests to">Content hash {{ substr($wp->content_hash, 0, 16) }}…</p>
        @endif
        @if ($history->isNotEmpty())
            <details class="text-xs text-ink-500">
                <summary class="cursor-pointer select-none">{{ $history->count() }} superseded sign-off{{ $history->count() > 1 ? 's' : '' }}</summary>
                <ul class="mt-2 space-y-1.5">
                    @foreach ($history as $h)
                        <li><span class="font-medium text-ink-700">{{ $h->user->name }}</span> ({{ $h->role }}) {{ local_time($h->signed_at) }} — invalidated {{ local_time($h->invalidated_at) }}: {{ $h->invalidation_reason }}</li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
</div>
