{{-- Review notes: $wp, $engagement, $canRaiseNotes, $canEdit --}}
<div class="card">
    <div class="card-header"><h2 class="card-title">Review notes</h2><span class="text-xs text-ink-500">{{ $wp->reviewNotes->where('status', '!=', \App\Enums\ReviewNoteStatus::Cleared)->count() }} open</span></div>
    <ul class="divide-y divide-navy-50">
        @forelse ($wp->reviewNotes as $note)
            <li class="px-5 py-3 text-sm" x-data="{ reply: false }">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs text-ink-500"><span class="font-medium text-ink-700">{{ $note->raiser->name }}</span> · {{ local_time($note->created_at) }}</span>
                    <x-status :value="$note->status" />
                </div>
                <p class="mt-1 whitespace-pre-line text-ink-900">{{ $note->body }}</p>
                @if ($note->response)
                    <div class="mt-2 rounded-md bg-navy-50/60 px-3 py-2 text-ink-700"><span class="text-xs font-medium text-ink-500">{{ $note->responder?->name }} responded:</span><p class="whitespace-pre-line">{{ $note->response }}</p></div>
                @endif
                @if ($note->status === \App\Enums\ReviewNoteStatus::Cleared)
                    <p class="mt-1 text-xs text-ink-400">Cleared by {{ $note->clearer?->name }} {{ local_time($note->cleared_at) }}</p>
                @elseif (! $engagement->status->isLocked())
                    <div class="mt-2 flex gap-2">
                        @if ($canEdit)<button type="button" class="btn-ghost btn-sm" @click="reply = !reply">{{ $note->response ? 'Update response' : 'Respond' }}</button>@endif
                        @if ($note->status === \App\Enums\ReviewNoteStatus::Responded && ($note->raised_by === auth()->id() || $engagement->roleOf(auth()->user()) === \App\Enums\EngagementRole::EngagementPartner))
                            <form method="POST" action="{{ route('working-papers.notes.clear', [$engagement, $wp, $note]) }}">@csrf<button class="btn-secondary btn-sm">Clear note</button></form>
                        @endif
                    </div>
                    @if ($canEdit)
                        <form x-show="reply" x-cloak method="POST" action="{{ route('working-papers.notes.respond', [$engagement, $wp, $note]) }}" class="mt-2 space-y-2">
                            @csrf
                            <textarea name="response" rows="2" class="input" required placeholder="Explain how the point was addressed">{{ $note->response }}</textarea>
                            <button class="btn-primary btn-sm">Save response</button>
                        </form>
                    @endif
                @endif
            </li>
        @empty
            <li class="px-5 py-4 text-sm text-ink-500">No review notes.</li>
        @endforelse
    </ul>
    @if ($canRaiseNotes)
        <form method="POST" action="{{ route('working-papers.notes.store', [$engagement, $wp]) }}" class="space-y-2 border-t border-navy-50 px-5 py-4">
            @csrf
            <textarea name="body" rows="2" class="input" placeholder="Raise a review point…" required></textarea>
            <button class="btn-secondary btn-sm">Raise note</button>
        </form>
    @endif
</div>
