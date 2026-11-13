<x-layouts.app :title="'Understanding the entity · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <form method="POST" action="{{ route('entity-understanding.update', $engagement) }}" class="card">
                @csrf @method('PUT')
                <div class="card-header">
                    <div><h2 class="card-title">{{ $wp?->reference ?? 'B1' }} · Understanding the entity and its environment</h2>
                        <p class="mt-0.5 text-xs text-ink-500">Structured per ISA 315 (Revised 2019) topics. Cite authoritative paragraphs from the library rather than paraphrasing the standard.</p></div>
                </div>
                @if ($wp && $wp->status !== \App\Enums\WorkingPaperStatus::Draft && $canEdit)
                    <p class="mx-5 mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">Signed off. Saving changes invalidates the sign-offs.</p>
                @endif
                <div class="divide-y divide-navy-50" x-data="{ open: null }">
                    @foreach ($sections as $key => [$title, $guide])
                        @php $row = $content->get($key); @endphp
                        <div class="px-5 py-4">
                            <button type="button" class="flex w-full items-center justify-between text-left" @click="open = open === '{{ $key }}' ? null : '{{ $key }}'">
                                <span class="text-sm font-semibold text-navy-900">{{ $loop->iteration }}. {{ $title }}</span>
                                <span class="flex items-center gap-2 text-xs">
                                    @if (filled($row?->content))<span class="badge badge-success badge-dot">Documented</span>@else<span class="badge badge-neutral">Empty</span>@endif
                                    <svg class="h-4 w-4 text-ink-400 transition" :class="open === '{{ $key }}' && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                                </span>
                            </button>
                            <div x-show="open === '{{ $key }}'" x-cloak class="mt-3">
                                <p class="mb-2 text-xs text-ink-500">{{ $guide }}</p>
                                @if ($canEdit)
                                    <textarea name="sections[{{ $key }}]" rows="6" class="input text-sm">{{ old('sections.'.$key, $row?->content) }}</textarea>
                                @else
                                    <div class="whitespace-pre-line rounded-md bg-navy-50/50 px-3 py-2 text-sm text-ink-700">{{ $row?->content ?: '—' }}</div>
                                @endif
                                @if ($row?->editor)<p class="mt-1 text-2xs text-ink-400">Last edited by {{ $row->editor->name }} {{ local_time($row->updated_at) }}</p>@endif
                            </div>
                            @if (filled($row?->content))
                                <p x-show="open !== '{{ $key }}'" class="mt-1 line-clamp-2 text-sm text-ink-600">{{ $row->content }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if ($canEdit)
                    <div class="flex justify-end border-t border-navy-100/70 px-5 py-3"><button class="btn-primary">Save understanding</button></div>
                @endif
            </form>
        </div>
        <div class="space-y-6">
            @if ($wp)
                @include('working-papers.partials.signoffs')
                @include('working-papers.partials.notes')
                @include('working-papers.partials.evidence')
            @else
                <div class="card card-body text-sm text-ink-500">Not started. A team member with edit rights opens this paper to begin.</div>
            @endif
        </div>
    </div>
</x-layouts.app>
