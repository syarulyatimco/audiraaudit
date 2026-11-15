{{-- Change history of management's financial statements, shown to both management and the audit team (ADR-051). --}}
@props(['history'])
<div {{ $attributes->merge(['class' => 'card']) }}>
    <div class="card-header">
        <div><h2 class="card-title">Change history</h2><p class="text-xs text-ink-500">Every change to the draft, by management or the audit team, and every submission and review.</p></div>
        <span class="text-xs text-ink-500">{{ $history->count() }}</span>
    </div>
    <ul class="divide-y divide-navy-50 text-sm">
        @forelse ($history as $h)
            @php $firm = ($h->properties['side'] ?? null) === 'firm' || in_array($h->event, ['client_fs.accepted', 'client_fs.changes_requested', 'client_fs.reopened', 'client_fs.tieout_saved'], true); @endphp
            <li class="flex items-start justify-between gap-3 px-5 py-2.5">
                <div class="min-w-0">
                    <div class="text-ink-800">{{ $h->description }}</div>
                    <div class="text-xs text-ink-500">{{ $h->actor_label }}</div>
                </div>
                <div class="shrink-0 text-right">
                    <span class="badge {{ $firm ? 'badge-info' : 'badge-neutral' }}">{{ $firm ? 'Audit team' : 'Management' }}</span>
                    <div class="mt-0.5 text-2xs text-ink-400">{{ local_time($h->created_at, 'j M Y H:i') }}</div>
                </div>
            </li>
        @empty
            <li class="px-5 py-4 text-ink-500">No changes recorded yet.</li>
        @endforelse
    </ul>
</div>
