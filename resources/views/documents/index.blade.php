<x-layouts.app :title="'Documents · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <form method="GET" class="mb-4 flex flex-wrap gap-3">
                <select name="category" class="input max-w-[14rem]" onchange="this.form.submit()">
                    <option value="">All categories</option>
                    @foreach (\App\Enums\DocumentCategory::options() as $v => $l)<option value="{{ $v }}" @selected(($filters['category'] ?? '') === $v)>{{ $l }}</option>@endforeach
                </select>
                <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" name="archived" value="1" class="checkbox" onchange="this.form.submit()" @checked(! empty($filters['archived']))> Show archived</label>
            </form>
            <div class="card overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Document</th><th>Category</th><th>Version</th><th>Scan</th><th class="text-right">Used in WPs</th></tr></thead>
                    <tbody>
                    @forelse ($documents as $d)
                        <tr class="row-link">
                            <td><a href="{{ route('documents.show', [$engagement, $d]) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $d->title }}</a>
                                <div class="text-xs text-ink-500">{{ $d->currentVersion?->original_name }} · {{ $d->currentVersion?->sizeLabel() }} · {{ $d->uploader->name }}</div></td>
                            <td class="text-ink-600">{{ $d->category->label() }}</td>
                            <td class="tabular-nums">v{{ $d->currentVersion?->version_no }}<span class="text-ink-400"> / {{ $d->versions_count }}</span></td>
                            <td>@if($d->currentVersion)@php $tone = ['clean' => 'success', 'pending' => 'warn', 'infected' => 'danger', 'failed' => 'danger'][$d->currentVersion->scan_status->value]; @endphp<span class="badge badge-dot badge-{{ $tone }}">{{ $d->currentVersion->scan_status->label() }}</span>@endif</td>
                            <td class="text-right tabular-nums">{{ $d->working_papers_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-empty title="No documents" description="Upload client documents, confirmations and other evidence. Files are hashed, scanned and versioned." /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($canUpload)
            <form method="POST" action="{{ route('documents.store', $engagement) }}" enctype="multipart/form-data" class="card h-max">
                @csrf
                <div class="card-header"><h2 class="card-title">Upload document</h2></div>
                <div class="card-body space-y-4">
                    <div>
                        <label class="label" for="file">File <span class="text-red-500">*</span></label>
                        <input id="file" type="file" name="file" required class="block w-full text-sm text-ink-600 file:mr-3 file:rounded-md file:border-0 file:bg-navy-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-navy-800 hover:file:bg-navy-100">
                        <p class="hint">{{ strtoupper(implode(', ', config('audira.documents.extensions'))) }} · max {{ round(config('audira.documents.max_kb') / 1024) }} MB</p>
                        @error('file')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <x-field name="title" label="Title" hint="Defaults to the file name." />
                    <x-select name="category" label="Category" :options="\App\Enums\DocumentCategory::options()" value="evidence" required />
                    <x-field name="description" label="Description" type="textarea" rows="2" />
                    <button class="btn-primary w-full">Upload</button>
                </div>
            </form>
        @endif
    </div>
</x-layouts.app>
