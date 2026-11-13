<x-layouts.app :title="$standard->reference">
    <x-page-header :eyebrow="$standard->framework->name" :title="$standard->reference.' — '.$standard->title" />
    <div class="grid gap-6 lg:grid-cols-4">
        <div class="lg:col-span-3">
            @if (! $version)
                <div class="card"><x-empty title="No published version" description="This standard has not been loaded from an authorised source yet." /></div>
            @else
                @if ($version->status === \App\Enums\LibraryVersionStatus::Superseded)
                    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">You are viewing a superseded version ({{ $version->version_label }}). It remains available because working papers may cite it.</div>
                @endif
                <div class="card">
                    <div class="card-header"><div><h2 class="card-title">{{ $version->version_label }}</h2><p class="text-xs text-ink-500">Source: {{ $version->source_title }}@if($version->source_url) · <a href="{{ $version->source_url }}" target="_blank" rel="noopener noreferrer" class="text-teal-700">link</a>@endif{{ $version->effective_date ? ' · effective '.$version->effective_date->format('j M Y') : '' }}</p></div><x-status :value="$version->status" /></div>
                    <div class="divide-y divide-navy-50">
                        @foreach ($version->paragraphs as $p)
                            <div id="p-{{ $p->id }}" class="grid grid-cols-[4rem_1fr] gap-4 px-5 py-3 target:bg-teal-50">
                                <div class="font-mono text-xs font-semibold text-navy-700">¶{{ $p->paragraph_ref }}</div>
                                <div>@if($p->heading)<div class="text-sm font-semibold text-navy-900">{{ $p->heading }}</div>@endif<p class="whitespace-pre-line text-sm text-ink-700">{{ $p->body }}</p></div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
        <div class="card h-max">
            <div class="card-header"><h2 class="card-title">Versions</h2></div>
            <ul class="divide-y divide-navy-50">
                @forelse ($standard->versions as $v)
                    <li class="px-5 py-2.5 text-sm"><a href="{{ route('library.standard', ['standard' => $standard, 'version' => $v->uuid]) }}" class="{{ $version?->id === $v->id ? 'font-semibold text-navy-900' : 'text-ink-600 hover:text-navy-900' }}">{{ $v->version_label }}</a>
                        <div class="text-xs text-ink-400">{{ $v->status->label() }} · {{ local_time($v->published_at, 'j M Y') }}</div></li>
                @empty
                    <li class="px-5 py-3 text-sm text-ink-500">None</li>
                @endforelse
            </ul>
        </div>
    </div>
</x-layouts.app>
