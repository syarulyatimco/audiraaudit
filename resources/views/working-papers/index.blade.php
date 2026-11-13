<x-layouts.app :title="'Working papers · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <div class="mb-5 flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap gap-6 text-sm">
            <div><span class="text-2xl font-semibold tabular-nums text-navy-900">{{ $stats['total'] }}</span> <span class="text-ink-500">papers</span></div>
            <div><span class="text-2xl font-semibold tabular-nums text-emerald-700">{{ $stats['reviewed'] }}</span> <span class="text-ink-500">reviewed</span></div>
            <div><span class="text-2xl font-semibold tabular-nums text-amber-700">{{ $stats['prepared'] }}</span> <span class="text-ink-500">awaiting review</span></div>
            <div><span class="text-2xl font-semibold tabular-nums text-red-700">{{ $stats['open_notes'] }}</span> <span class="text-ink-500">open review notes</span></div>
        </div>
        @if ($canEdit)<a href="{{ route('working-papers.create', $engagement) }}" class="btn-primary">New working paper</a>@endif
    </div>

    @if ($grouped->isEmpty())
        <div class="card"><x-empty title="No working papers yet" description="Start with the entity understanding (B1), or add a working paper for any section of the file.">
            <a href="{{ route('entity-understanding.show', $engagement) }}" class="btn-secondary btn-sm">Open entity understanding</a>
        </x-empty></div>
    @endif

    <div class="space-y-6">
        @foreach (\App\Enums\WorkingPaperSection::cases() as $section)
            @continue(! $grouped->has($section->value))
            <div class="card overflow-x-auto">
                <div class="card-header"><h2 class="card-title"><span class="mr-2 font-mono text-ink-400">{{ $section->prefix() }}</span>{{ $section->label() }}</h2>
                    @if ($canEdit)<a href="{{ route('working-papers.create', [$engagement, 'section' => $section->value]) }}" class="text-xs font-medium text-teal-700">Add</a>@endif</div>
                <table class="table">
                    <thead><tr><th class="w-24">Ref</th><th>Title</th><th>Prepared</th><th>Reviewed</th><th class="text-right">Evidence</th><th class="text-right">Notes</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach ($grouped[$section->value] as $p)
                        @php $pre = $p->activeSignoffs->firstWhere('role', 'preparer'); $rev = $p->activeSignoffs->firstWhere('role', 'reviewer'); @endphp
                        <tr class="row-link">
                            <td class="font-mono text-xs font-semibold text-navy-800">{{ $p->reference }}</td>
                            <td><a href="{{ route('working-papers.show', [$engagement, $p]) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $p->title }}</a></td>
                            <td class="text-xs text-ink-600">{{ $pre ? $pre->user->initials().' · '.local_time($pre->signed_at, 'j M') : '—' }}</td>
                            <td class="text-xs text-ink-600">{{ $rev ? $rev->user->initials().' · '.local_time($rev->signed_at, 'j M') : '—' }}</td>
                            <td class="text-right tabular-nums">{{ $p->documents_count }}</td>
                            <td class="text-right tabular-nums {{ $p->open_notes_count ? 'font-semibold text-red-700' : '' }}">{{ $p->open_notes_count }}</td>
                            <td><x-status :value="$p->status" /></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
</x-layouts.app>
