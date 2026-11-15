<x-layouts.portal :title="'Financial statements · '.$engagement->name">
    <a href="{{ route('portal.engagement', $engagement) }}" class="text-xs text-ink-500 hover:text-navy-900">← {{ $engagement->name }}</a>
    <div class="mt-1 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Financial statements</h1>
            <p class="mt-1 text-sm text-ink-500">{{ $engagement->client->name }} · {{ $engagement->periodLabel() }} · prepared by management for audit</p>
        </div>
        @if ($fs)
            @php $tone = ['draft' => 'neutral', 'submitted' => 'info', 'changes_requested' => 'warn', 'accepted' => 'success'][$fs->status]; @endphp
            <span class="badge badge-dot badge-{{ $tone }}">{{ ['draft' => 'Draft', 'submitted' => 'With your auditors', 'changes_requested' => 'Changes requested', 'accepted' => 'Accepted by your auditors'][$fs->status] }}</span>
        @endif
    </div>
    @error('fs')<p class="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    @if (! $fs)
        <div class="card mt-6">
            <div class="card-header"><h2 class="card-title">Set up your financial statements</h2></div>
            <form method="POST" action="{{ route('portal.fs.start', $engagement) }}" class="card-body space-y-3 text-sm">
                @csrf
                <p class="text-ink-600">As management, you prepare the financial statements and your auditors audit them. Choose a starting layout; you can rename, add and remove every line and note afterwards. Your audit team can also help with the draft — every change shows in the change history, and only you submit versions to them.</p>
                <select name="template" class="input max-w-md">@foreach ($templates as $k => $l)<option value="{{ $k }}" @selected($k === $suggested)>{{ $l }}</option>@endforeach</select>
                @error('template')<p class="error">{{ $message }}</p>@enderror
                <button class="btn-primary">Start</button>
            </form>
        </div>
    @else
        @php $latest = $versions->first(); @endphp
        @if ($latest && $latest->review_status === 'changes_requested')
            <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"><span class="font-semibold">Your auditors asked for changes to version {{ $latest->version }}:</span><div class="mt-1 whitespace-pre-line">{{ $latest->review_comment }}</div></div>
        @endif

        <div class="mt-6" x-data="{ tab: 'sofp' }">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="inline-flex flex-wrap rounded-md border border-navy-100 bg-white p-0.5 text-sm">
                    @foreach (\App\Models\ClientFinancialStatement::STATEMENTS as $k)
                        <button type="button" @click="tab = '{{ $k }}'" :class="tab === '{{ $k }}' ? 'bg-navy-800 text-white' : 'text-ink-600 hover:text-navy-900'" class="rounded px-3 py-1.5 font-medium">{{ \Illuminate\Support\Str::limit($fs->label($k), 34) }}</button>
                    @endforeach
                    <button type="button" @click="tab = 'notes'" :class="tab === 'notes' ? 'bg-navy-800 text-white' : 'text-ink-600 hover:text-navy-900'" class="rounded px-3 py-1.5 font-medium">Notes</button>
                    <button type="button" @click="tab = 'submit'" :class="tab === 'submit' ? 'bg-navy-800 text-white' : 'text-ink-600 hover:text-navy-900'" class="rounded px-3 py-1.5 font-medium">Submit &amp; history</button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('portal.fs.export', $engagement) }}" class="btn-secondary btn-sm">Download .xlsx</a>
                    @unless ($locked)<button type="button" class="btn-secondary btn-sm" onclick="document.getElementById('fs-upload').click()">Upload .xlsx</button>@endunless
                    @if ($pdf)<a href="{{ route('portal.fs.pdf', $engagement) }}" class="btn-secondary btn-sm">PDF</a>@endif
                </div>
            </div>
            @unless ($locked)
                <form method="POST" action="{{ route('portal.fs.import', $engagement) }}" enctype="multipart/form-data" class="hidden">@csrf<input type="file" id="fs-upload" name="file" accept=".xlsx" onchange="this.form.submit()"></form>
            @endunless
            @error('file')<p class="error mt-2">{{ $message }}</p>@enderror

            <x-fs-editor :fs="$fs" :statement-url="route('portal.fs.statement', [$engagement, '__KEY__'])" :notes-url="route('portal.fs.notes', $engagement)" :locked="$locked" />

            <div x-show="tab === 'submit'" x-cloak class="mt-4 grid gap-6 lg:grid-cols-2">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Send to your auditors</h2></div>
                    @if ($locked)
                        <p class="card-body text-sm text-ink-600">Your auditors have accepted version {{ $versions->firstWhere('review_status', 'accepted')?->version }}. Ask them to re-open the statements if something must change.</p>
                    @else
                        <form method="POST" action="{{ route('portal.fs.submit', $engagement) }}" class="card-body space-y-3 text-sm" x-data="{ final: false }">
                            @csrf
                            <p class="text-ink-600">Save every statement first. Each submission is kept as a version your auditors review.</p>
                            <ul class="space-y-1 text-xs">
                                <li class="{{ $checks['sofp_balanced'] ? 'text-emerald-700' : 'text-red-700' }}">{{ $checks['sofp_balanced'] ? '✓' : '✕' }} Statement of financial position balances</li>
                            </ul>
                            <label class="flex items-start gap-2"><input type="checkbox" name="directors_approved" value="1" class="checkbox mt-0.5" x-model="final"><span>This is the final version, approved by the directors / committee.</span></label>
                            <div x-show="final" x-cloak class="space-y-3">
                                <x-field name="approved_by_names" label="Approved by (names)" />
                                <x-field name="approved_on" label="Date of approval" type="date" />
                            </div>
                            @error('submit')<p class="error">{{ $message }}</p>@enderror
                            <button class="btn-primary">Submit to auditors</button>
                        </form>
                    @endif
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Versions</h2></div>
                    <ul class="divide-y divide-navy-50 text-sm">
                        @forelse ($versions as $v)
                            <li class="px-5 py-3">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-medium">Version {{ $v->version }} · {{ local_time($v->submitted_at, 'j M Y, g:i a') }}</span>
                                    <span class="badge {{ ['received' => 'badge-info', 'changes_requested' => 'badge-warn', 'accepted' => 'badge-success', 'superseded' => 'badge-neutral'][$v->review_status] }}">{{ ['received' => 'With auditors', 'changes_requested' => 'Changes requested', 'accepted' => 'Accepted', 'superseded' => 'Superseded'][$v->review_status] }}</span>
                                </div>
                                <div class="text-xs text-ink-500">{{ $v->directors_approved ? 'Approved by '.$v->approved_by_names.' on '.$v->approved_on->format('j M Y') : 'Draft for discussion' }}@if ($pdf) · <a class="text-teal-700 hover:underline" href="{{ route('portal.fs.pdf', [$engagement, 'version' => $v->uuid]) }}">PDF</a>@endif</div>
                                @if ($v->review_comment)<p class="mt-1 whitespace-pre-line text-xs text-ink-700">{{ $v->review_comment }}</p>@endif
                            </li>
                        @empty
                            <li class="px-5 py-4 text-ink-500">Nothing submitted yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
            <x-fs-history :history="$history" x-show="tab === 'submit'" x-cloak class="mt-6" />
        </div>
    @endif
</x-layouts.portal>
