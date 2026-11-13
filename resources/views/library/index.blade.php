<x-layouts.app title="Audit library">
    <x-page-header title="Audit library" subtitle="Version-controlled authoritative sources. Every citation in a working paper points to an exact, immutable paragraph version.">
        <x-slot:actions>
            <form method="GET" action="{{ route('library.search') }}" class="flex gap-2"><input type="search" name="q" placeholder="Search paragraphs…" class="input w-64" minlength="2" required><button class="btn-secondary">Search</button></form>
        </x-slot:actions>
    </x-page-header>
    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ($frameworks as $fw)
            <div class="card">
                <div class="card-header"><div><h2 class="card-title">{{ $fw->name }}</h2><p class="text-xs text-ink-500">{{ $fw->category->label() }}{{ $fw->issuer ? ' · '.$fw->issuer : '' }}</p></div></div>
                <ul class="divide-y divide-navy-50">
                    @forelse ($fw->standards as $st)
                        <li class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                            <a href="{{ route('library.standard', $st) }}" class="min-w-0 truncate text-navy-900 hover:text-teal-700"><span class="font-semibold">{{ $st->reference }}</span> {{ $st->title }}</a>
                            @if ($st->publishedVersion)<span class="shrink-0 text-xs text-ink-500">{{ $st->publishedVersion->version_label }} · {{ $st->publishedVersion->paragraphs_count }} ¶</span>
                            @else<span class="badge badge-neutral shrink-0">Not yet loaded</span>@endif
                        </li>
                    @empty
                        <li class="px-5 py-4 text-sm text-ink-500">No standards loaded yet. Content is added by AUDIRA from authorised sources.</li>
                    @endforelse
                </ul>
            </div>
        @endforeach
    </div>
</x-layouts.app>
