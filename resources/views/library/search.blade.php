<x-layouts.app title="Library search">
    <x-page-header title="Library search" subtitle="Searches the current published version of every standard." />
    <form method="GET" class="mb-5 flex gap-2"><input type="search" name="q" value="{{ $q }}" class="input max-w-lg" minlength="2" required autofocus><button class="btn-primary">Search</button></form>
    @if ($q !== '')
        <div class="card divide-y divide-navy-50">
            @forelse ($results as $p)
                <a href="{{ route('library.standard', $p->version->standard) }}#p-{{ $p->id }}" class="block px-5 py-3 hover:bg-navy-50/40">
                    <div class="text-sm font-semibold text-navy-900">{{ $p->citation() }} <span class="font-normal text-ink-500">· {{ $p->version->standard->title }}</span></div>
                    <p class="mt-0.5 line-clamp-2 text-sm text-ink-600">{{ $p->heading ? $p->heading.' — ' : '' }}{{ $p->body }}</p>
                </a>
            @empty
                <x-empty title="No matches" description="Try different terms or a standard reference such as “ISA 530”." />
            @endforelse
        </div>
    @endif
</x-layouts.app>
