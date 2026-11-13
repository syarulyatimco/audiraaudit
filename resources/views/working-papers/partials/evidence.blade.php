{{-- Evidence links + citations: $wp, $engagement, $linkable, $canEdit --}}
<div class="card">
    <div class="card-header"><h2 class="card-title">Evidence</h2><a href="{{ route('documents.index', $engagement) }}" class="text-xs font-medium text-teal-700">All documents</a></div>
    <ul class="divide-y divide-navy-50">
        @forelse ($wp->documents as $doc)
            <li class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                <a href="{{ route('documents.show', [$engagement, $doc]) }}" class="min-w-0 truncate font-medium text-navy-900 hover:text-teal-700">{{ $doc->title }}<span class="ml-1 text-xs font-normal text-ink-400">v{{ $doc->currentVersion?->version_no }}</span></a>
                @if ($canEdit)
                    <form method="POST" action="{{ route('working-papers.documents.unlink', [$engagement, $wp, $doc]) }}">@csrf @method('DELETE')<button class="text-xs text-ink-400 hover:text-red-700">Unlink</button></form>
                @endif
            </li>
        @empty
            <li class="px-5 py-4 text-sm text-ink-500">No evidence linked.</li>
        @endforelse
    </ul>
    @if ($canEdit && $linkable->isNotEmpty())
        <form method="POST" action="{{ route('working-papers.documents.link', [$engagement, $wp]) }}" class="flex gap-2 border-t border-navy-50 px-5 py-3">
            @csrf
            <select name="document_id" class="input py-1.5 text-sm" required>@foreach ($linkable as $d)<option value="{{ $d->id }}">{{ $d->title }}</option>@endforeach</select>
            <button class="btn-secondary btn-sm">Link</button>
        </form>
    @endif
</div>

<div class="card" x-data="{ q: '', results: [], loading: false, picked: null,
        async search() { if (this.q.length < 2) { this.results = []; return; } this.loading = true;
            const r = await fetch('{{ route('library.search') }}?q=' + encodeURIComponent(this.q), { headers: { 'Accept': 'application/json' } });
            this.results = r.ok ? await r.json() : []; this.loading = false; } }">
    <div class="card-header"><h2 class="card-title">Authoritative references</h2><a href="{{ route('library.index') }}" class="text-xs font-medium text-teal-700">Library</a></div>
    <ul class="divide-y divide-navy-50">
        @forelse ($wp->citations as $c)
            @php $p = $c->paragraph; @endphp
            <li class="px-5 py-2.5 text-sm">
                <div class="flex items-center justify-between gap-2">
                    <a href="{{ route('library.standard', ['standard' => $p->version->standard, 'version' => $p->version->uuid]) }}#p-{{ $p->id }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $p->citation() }}</a>
                    <span class="flex items-center gap-2">
                        @if ($p->version->status === \App\Enums\LibraryVersionStatus::Superseded)<span class="badge badge-warn">Superseded</span>@endif
                        @if ($canEdit)<form method="POST" action="{{ route('working-papers.citations.destroy', [$engagement, $wp, $c]) }}">@csrf @method('DELETE')<button class="text-xs text-ink-400 hover:text-red-700">Remove</button></form>@endif
                    </span>
                </div>
                @if ($c->note)<p class="text-xs text-ink-500">{{ $c->note }}</p>@endif
            </li>
        @empty
            <li class="px-5 py-4 text-sm text-ink-500">No references cited. Cite only paragraphs from the published library.</li>
        @endforelse
    </ul>
    @if ($canEdit)
        <div class="space-y-2 border-t border-navy-50 px-5 py-3">
            <input type="search" x-model="q" @input.debounce.350ms="search()" placeholder="Search the library (e.g. materiality benchmark)" class="input text-sm">
            <p x-show="loading" x-cloak class="text-xs text-ink-400">Searching…</p>
            <template x-for="r in results" :key="r.id">
                <form method="POST" action="{{ route('working-papers.citations.store', [$engagement, $wp]) }}" class="rounded-md border border-navy-50 p-2.5 text-xs">
                    @csrf
                    <input type="hidden" name="library_paragraph_id" :value="r.id">
                    <div class="flex items-start justify-between gap-2"><span class="font-semibold text-navy-900" x-text="r.citation"></span><button class="btn-secondary btn-sm">Cite</button></div>
                    <p class="mt-1 text-ink-600" x-text="r.excerpt"></p>
                </form>
            </template>
            <p x-show="q.length >= 2 && !loading && results.length === 0" x-cloak class="text-xs text-ink-400">No published paragraphs match.</p>
        </div>
    @endif
</div>
