<x-layouts.app :title="$version->standard->reference.' '.$version->version_label">
    <x-page-header :eyebrow="'Superadmin · '.$version->standard->framework->name" :title="$version->standard->reference.' — '.$version->version_label" :subtitle="$version->standard->title">
        <x-slot:actions>
            <x-status :value="$version->status" />
            @if ($version->isDraft())
                <form method="POST" action="{{ route('admin.library.versions.publish', $version) }}" onsubmit="return confirm('Publish? Paragraphs become immutable and the current published version is superseded.')">@csrf<button class="btn-accent">Publish version</button></form>
            @endif
        </x-slot:actions>
    </x-page-header>
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <div class="card-header"><h2 class="card-title">Paragraphs ({{ $version->paragraphs->count() }})</h2>@unless($version->isDraft())<span class="text-xs text-ink-500">Immutable</span>@endunless</div>
            <div class="divide-y divide-navy-50">
                @forelse ($version->paragraphs as $p)
                    <div class="px-5 py-3" x-data="{ edit: false }">
                        <div class="flex items-start justify-between gap-3" x-show="!edit">
                            <div class="flex gap-4"><span class="w-14 shrink-0 font-mono text-xs font-semibold text-navy-700">¶{{ $p->paragraph_ref }}</span>
                                <div>@if($p->heading)<div class="text-sm font-semibold">{{ $p->heading }}</div>@endif<p class="whitespace-pre-line text-sm text-ink-700">{{ $p->body }}</p></div></div>
                            @if ($version->isDraft())
                                <div class="flex shrink-0 gap-1"><button type="button" class="btn-ghost btn-sm" @click="edit = true">Edit</button>
                                    <form method="POST" action="{{ route('admin.library.paragraphs.destroy', [$version, $p]) }}">@csrf @method('DELETE')<button class="btn-ghost btn-sm text-red-700">Delete</button></form></div>
                            @endif
                        </div>
                        @if ($version->isDraft())
                            <form x-show="edit" x-cloak method="POST" action="{{ route('admin.library.paragraphs.update', [$version, $p]) }}" class="space-y-2">
                                @csrf @method('PUT')
                                <div class="flex gap-2"><input name="paragraph_ref" value="{{ $p->paragraph_ref }}" class="input w-28" required><input name="heading" value="{{ $p->heading }}" class="input" placeholder="Heading"></div>
                                <textarea name="body" rows="4" class="input" required>{{ $p->body }}</textarea>
                                <div class="flex gap-2"><button class="btn-primary btn-sm">Save</button><button type="button" class="btn-ghost btn-sm" @click="edit = false">Cancel</button></div>
                            </form>
                        @endif
                    </div>
                @empty
                    <x-empty title="No paragraphs yet" description="Add paragraphs individually or import a CSV transcribed from the authorised source." />
                @endforelse
            </div>
            @if ($version->isDraft())
                <form method="POST" action="{{ route('admin.library.paragraphs.store', $version) }}" class="space-y-2 border-t border-navy-100 px-5 py-4">
                    @csrf
                    <div class="flex gap-2"><input name="paragraph_ref" class="input w-28" placeholder="Ref (e.g. 10)" required><input name="heading" class="input" placeholder="Heading (optional)"></div>
                    <textarea name="body" rows="3" class="input" placeholder="Paragraph text exactly as in the authorised source" required></textarea>
                    <button class="btn-secondary btn-sm">Add paragraph</button>
                </form>
            @endif
        </div>
        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Source</h2></div>
                <dl class="card-body">
                    <div class="dl-row"><dt>Title</dt><dd>{{ $version->source_title }}</dd></div>
                    <div class="dl-row"><dt>URL</dt><dd class="max-w-[12rem] truncate">@if($version->source_url)<a href="{{ $version->source_url }}" target="_blank" rel="noopener noreferrer" class="text-teal-700">{{ $version->source_url }}</a>@else—@endif</dd></div>
                    <div class="dl-row"><dt>File</dt><dd>@if($version->source_path)<a href="{{ route('admin.library.versions.source', $version) }}" class="text-teal-700">{{ $version->source_filename }}</a>@else—@endif</dd></div>
                    @if($version->source_sha256)<div class="dl-row"><dt>SHA-256</dt><dd class="font-mono text-2xs">{{ substr($version->source_sha256, 0, 16) }}…</dd></div>@endif
                    <div class="dl-row"><dt>Effective</dt><dd>{{ $version->effective_date?->format('j M Y') ?? '—' }}</dd></div>
                    <div class="dl-row"><dt>Published</dt><dd>{{ $version->published_at ? local_time($version->published_at).' by '.$version->publisher?->name : '—' }}</dd></div>
                </dl>
                @if ($version->isDraft() && ! $version->source_path)
                    <form method="POST" action="{{ route('admin.library.versions.source.store', $version) }}" enctype="multipart/form-data" class="flex gap-2 border-t border-navy-50 px-5 py-3">@csrf<input type="file" name="source_file" class="block w-full text-xs" required><button class="btn-secondary btn-sm">Attach</button></form>
                @endif
            </div>
            @if ($version->isDraft())
                <form method="POST" action="{{ route('admin.library.versions.import', $version) }}" enctype="multipart/form-data" class="card">
                    @csrf
                    <div class="card-header"><h2 class="card-title">Import CSV</h2></div>
                    <div class="card-body space-y-3">
                        <p class="text-xs text-ink-500">Header row: <code class="font-mono">paragraph_ref,heading,body</code>. The whole file is rejected if any row is invalid.</p>
                        <input type="file" name="csv" accept=".csv,text/csv" required class="block w-full text-sm">
                        @error('csv')<p class="error">{{ $message }}</p>@enderror
                        <button class="btn-primary w-full">Import</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</x-layouts.app>
