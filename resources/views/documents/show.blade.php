<x-layouts.app :title="$document->title">
    <x-engagement-nav :engagement="$engagement" />
    <div class="mb-4"><a href="{{ route('documents.index', $engagement) }}" class="text-sm text-ink-500 hover:text-navy-900">← Documents</a></div>
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card">
                <div class="card-header"><div><h2 class="text-lg font-semibold text-navy-900">{{ $document->title }}</h2><p class="text-xs text-ink-500">{{ $document->category->label() }} · uploaded by {{ $document->uploader->name }} {{ local_time($document->created_at) }}</p></div>
                    @if ($document->archived_at)<span class="badge badge-neutral">Archived</span>@endif</div>
                @if ($document->description)<p class="card-body whitespace-pre-line text-sm text-ink-700">{{ $document->description }}</p>@endif
            </div>
            <div class="card overflow-x-auto">
                <div class="card-header"><h2 class="card-title">Version history</h2><span class="text-xs text-ink-500">Versions are immutable and never deleted</span></div>
                <table class="table">
                    <thead><tr><th>Version</th><th>File</th><th>SHA-256</th><th>Scan</th><th>Uploaded</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($document->versions as $v)
                        <tr>
                            <td class="font-semibold tabular-nums">v{{ $v->version_no }}@if($v->id === $document->current_version_id)<span class="badge badge-teal ml-1">Current</span>@endif</td>
                            <td><div class="max-w-[14rem] truncate">{{ $v->original_name }}</div><div class="text-xs text-ink-500">{{ $v->mime_type }} · {{ $v->sizeLabel() }}</div></td>
                            <td class="font-mono text-2xs text-ink-500" title="{{ $v->sha256 }}">{{ substr($v->sha256, 0, 12) }}…</td>
                            <td>
                                @php $tone = ['clean' => 'success', 'pending' => 'warn', 'infected' => 'danger', 'failed' => 'danger'][$v->scan_status->value]; @endphp
                                <span class="badge badge-dot badge-{{ $tone }}">{{ $v->scan_status->label() }}</span>
                                @if ($v->scan_detail)<div class="text-2xs text-red-700">{{ $v->scan_detail }}</div>@endif
                            </td>
                            <td class="text-xs text-ink-600">{{ $v->uploader->name }}<br>{{ local_time($v->created_at) }}</td>
                            <td class="text-right">@if ($v->isDownloadable())<a href="{{ route('documents.download', [$engagement, $document, $v->uuid]) }}" class="btn-secondary btn-sm">Download</a>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Used as evidence in</h2></div>
                <ul class="divide-y divide-navy-50">
                    @forelse ($document->workingPapers as $wp)
                        <li class="flex items-center justify-between px-5 py-2.5 text-sm"><a href="{{ route('working-papers.show', [$engagement, $wp]) }}" class="text-navy-900 hover:text-teal-700"><span class="font-mono text-xs font-semibold">{{ $wp->reference }}</span> {{ $wp->title }}</a><x-status :value="$wp->status" /></li>
                    @empty
                        <li class="px-5 py-4 text-sm text-ink-500">Not linked to any working paper.</li>
                    @endforelse
                </ul>
            </div>
            @if ($canUpload)
                <form method="POST" action="{{ route('documents.versions.store', [$engagement, $document]) }}" enctype="multipart/form-data" class="card">
                    @csrf
                    <div class="card-header"><h2 class="card-title">Upload new version</h2></div>
                    <div class="card-body space-y-3">
                        <input type="file" name="file" required class="block w-full text-sm text-ink-600 file:mr-3 file:rounded-md file:border-0 file:bg-navy-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-navy-800">
                        @error('file')<p class="error">{{ $message }}</p>@enderror
                        @if ($document->workingPapers->isNotEmpty())<p class="text-xs text-amber-800">Sign-offs on the working papers above will be invalidated.</p>@endif
                        <button class="btn-primary w-full">Upload version</button>
                    </div>
                </form>
            @endif
            @if ($canArchive)
                <form method="POST" action="{{ route('documents.archive', [$engagement, $document]) }}" onsubmit="return confirm('Archive this document? Versions are kept in the audit file.')">@csrf<button class="btn-danger w-full">Archive document</button></form>
            @endif
        </div>
    </div>
</x-layouts.app>
