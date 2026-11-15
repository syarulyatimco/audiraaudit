<x-layouts.app :title="$sheet->reference.' · '.$sheet->title">
    <x-engagement-nav :engagement="$engagement" />
    <x-fieldwork-nav :engagement="$engagement" />
    @php
        $base = route('programmes.rows.update', [$engagement, $sheet, '__ID__']);
        $cols = [
            ['key' => 'ref', 'label' => 'Ref', 'editable' => true, 'width' => '6.5rem'],
            ['key' => 'procedure', 'label' => 'Procedure', 'editable' => true, 'width' => '26rem'],
            ['key' => 'assertions', 'label' => 'Assertion', 'editable' => true, 'width' => '6.5rem'],
            ['key' => 'risk', 'label' => 'Risk', 'editable' => true, 'width' => '10rem'],
            ['key' => 'done_by', 'label' => 'Done by', 'editable' => false, 'width' => '8rem', 'locked' => 'Done by is recorded when the step is signed as done.'],
            ['key' => 'done_at', 'label' => 'Date', 'editable' => false, 'width' => '6.5rem', 'locked' => 'The date is recorded when the step is signed as done.'],
            ['key' => 'wp_ref', 'label' => 'WP ref', 'editable' => true, 'width' => '12rem', 'html' => true],
            ['key' => 'conclusion', 'label' => 'Conclusion', 'editable' => true, 'width' => '18rem', 'html' => true],
        ];
        $config = [
            'rows' => $rows, 'columns' => $cols, 'custom' => $custom,
            'canEdit' => $canEdit, 'canManage' => $canManage,
            'urls' => [
                'row' => $base, 'move' => $base.'/move', 'signoff' => $base.'/__ACTION__',
                'add' => route('programmes.rows.store', [$engagement, $sheet]),
                'columns' => route('programmes.columns.store', [$engagement, $sheet]),
                'procedure' => true,
            ],
            'procedures' => $procedures,
        ];
    @endphp

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <a href="{{ route('programmes.index', $engagement) }}" class="text-sm text-ink-500 hover:text-navy-900">← Programmes</a>
        <span class="text-ink-300">/</span>
        <span class="font-mono text-sm font-semibold text-navy-800">{{ $sheet->reference }}</span>
        <span class="text-sm font-medium text-navy-900">{{ $sheet->title }}</span>
        <span class="badge badge-neutral">{{ $sheet->stage->label() }}</span>
        @if ($sheet->fsli)<span class="badge badge-teal">{{ $sheet->fsli->code }}</span>@endif
        <div class="ml-auto">
            <select class="input !py-1 text-xs" onchange="if (this.value) window.location = this.value" aria-label="Go to programme">
                @foreach ($siblings as $s)<option value="{{ route('programmes.show', [$engagement, $s->uuid]) }}" @selected($s->id === $sheet->id)>{{ $s->reference }} · {{ \Illuminate\Support\Str::limit($s->title, 50) }}</option>@endforeach
            </select>
        </div>
    </div>
    @if ($sheet->objective)
        <p class="mb-4 max-w-4xl text-sm text-ink-600"><span class="font-semibold text-navy-900">Objective.</span> {{ $sheet->objective }}</p>
    @endif

    <x-xl-grid :config="$config" :signoffs="true" :can-review="$canReview" :user-id="$userId">
        <x-slot:toolbar>
            <a href="{{ route('programmes.export', [$engagement, $sheet]) }}" class="btn-secondary btn-sm">Download .xlsx</a>
            @if ($canManage)
                <button type="button" class="btn-secondary btn-sm" onclick="document.getElementById('programme-upload').click()">Upload .xlsx</button>
            @endif
            <div class="relative" x-data="{ open: false }">
                <button type="button" class="btn-secondary btn-sm" @click="open = ! open">Link evidence</button>
                <div x-cloak x-show="open" @click.outside="open = false" class="absolute right-0 top-9 z-40 max-h-72 w-80 overflow-y-auto rounded-md border border-navy-100 bg-white py-1 text-xs shadow-lg">
                    <p class="px-3 py-1.5 text-2xs uppercase tracking-wide text-ink-400">Add to the selected row's WP ref, or copy</p>
                    @forelse ($documents as $d)
                        <div class="flex items-center justify-between gap-2 px-3 py-1.5 hover:bg-navy-50">
                            <span class="truncate" title="{{ $d->title }}">📎 {{ $d->title }}</span>
                            <span class="flex shrink-0 gap-1">
                                @if ($canEdit)<button type="button" class="text-teal-700 hover:underline" @click="insertToken('[[doc:{{ $d->uuid }}]]'); open = false">Add</button>@endif
                                <button type="button" class="text-ink-500 hover:underline" @click="navigator.clipboard.writeText('[[doc:{{ $d->uuid }}]]'); toast('Document link copied')">Copy</button>
                            </span>
                        </div>
                    @empty
                        <p class="px-3 py-2 text-ink-500">No documents uploaded on this engagement yet.</p>
                    @endforelse
                </div>
            </div>
        </x-slot:toolbar>
    </x-xl-grid>

    @if ($canManage)
        <form method="POST" action="{{ route('programmes.import', [$engagement, $sheet]) }}" enctype="multipart/form-data" class="hidden">
            @csrf
            <input type="file" name="file" accept=".xlsx" id="programme-upload" onchange="this.form.submit()">
        </form>
        @error('file')<p class="error mt-2">{{ $message }}</p>@enderror
    @endif

    @if ($canManage)
        <div class="mt-6">
            @include('ai.partials.panel', ['agent' => 'audit_programme', 'task' => 'suggest_rows', 'subject' => $sheet->uuid, 'title' => 'AI step suggestions',
                'blurb' => 'The Audit Programme agent reads this programme and the risk register and proposes extra steps for risks not yet covered. Nothing is added until you choose rows.', 'show' => true])
        </div>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <div class="card-header"><h2 class="card-title">How linking works</h2></div>
            <div class="card-body space-y-2 text-sm text-ink-600">
                <p>Use <span class="font-medium">⋯ → Copy as link</span> on any step, or <span class="font-medium">Link evidence</span> for a document. Paste the copied link into a WP ref or conclusion here, into another programme, or into any working paper. It shows as a shortcut chip that opens the step or document, and keeps its live reference if the step is renumbered.</p>
                <p>Download .xlsx to work in Excel; upload it back to apply changes. Rows are matched on a hidden Row ID column; new rows and new columns are added. Done by, Date and review are recorded only in AUDIRA.</p>
                @if ($risks->isNotEmpty())<p class="text-xs"><span class="font-semibold text-navy-900">Risks on this engagement:</span> {{ $risks->join(' · ') }}</p>@endif
            </div>
        </div>
        @if ($canManage)
            <div class="card">
                <div class="card-header"><h2 class="card-title">Programme details</h2></div>
                <form method="POST" action="{{ route('programmes.update', [$engagement, $sheet]) }}" class="card-body space-y-3">
                    @csrf @method('PUT')
                    <x-field name="title" label="Title" :value="$sheet->title" required />
                    <x-field name="objective" label="Objective" type="textarea" rows="3" :value="$sheet->objective" />
                    <button class="btn-primary btn-sm">Save</button>
                </form>
                <form method="POST" action="{{ route('programmes.destroy', [$engagement, $sheet]) }}" class="border-t border-navy-100/70 px-5 py-3" onsubmit="return confirm('Remove this programme and its unsigned steps?')">
                    @csrf @method('DELETE')
                    <button class="btn-danger btn-sm">Remove programme</button>
                    @error('sheet')<p class="error">{{ $message }}</p>@enderror
                </form>
            </div>
        @endif
    </div>
</x-layouts.app>
