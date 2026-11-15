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
                <p class="text-ink-600">As management, you prepare the financial statements and your auditors audit them. Choose a starting layout; you can rename, add and remove every line and note afterwards.</p>
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

            @foreach (\App\Models\ClientFinancialStatement::STATEMENTS as $k)
                <div x-show="tab === '{{ $k }}'" x-cloak class="card mt-4" x-data="fsEditor(@js(['key' => $k, 'rows' => $fs->statements[$k]['rows'], 'url' => route('portal.fs.statement', [$engagement, $k])]))">
                    <div class="card-header">
                        <h2 class="card-title">{{ $fs->label($k) }}</h2>
                        <div class="flex items-center gap-3 text-xs">
                            <span x-show="dirty" class="text-amber-700">Unsaved changes</span>
                            <span x-show="saved" class="text-emerald-700">Saved</span>
                            @unless ($locked)<button type="button" class="btn-primary btn-sm" @click="save()" :disabled="saving">Save</button>@endunless
                        </div>
                    </div>
                    <p x-show="error" x-text="error" class="mx-5 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"></p>
                    <div class="overflow-x-auto">
                        <table class="table" style="table-layout:fixed">
                            <thead><tr><th style="width:7.5rem">Type</th>@if($k === 'sofp')<th style="width:10.5rem">Section</th>@endif<th>Caption</th><th style="width:4.5rem">Note</th><th class="text-right" style="width:10rem">Current year</th><th class="text-right" style="width:10rem">Prior year</th><th style="width:6rem"></th></tr></thead>
                            <tbody>
                                <template x-for="(r, i) in rows" :key="i">
                                    <tr :class="r.kind === 'heading' ? 'bg-navy-50/50' : ''">
                                        <td><select class="input !py-1 text-xs" x-model="r.kind" @change="dirty = true" @disabled($locked)><option value="heading">Heading</option><option value="line">Line</option><option value="subtotal">Subtotal</option></select></td>
                                        @if($k === 'sofp')<td><select class="input !py-1 text-xs" x-model="r.side" @change="dirty = true" @disabled($locked)><option value="asset">Assets</option><option value="el">Equity &amp; liabilities</option></select></td>@endif
                                        <td><input class="input !py-1 text-sm" :class="r.kind !== 'line' && 'font-semibold'" x-model="r.caption" @input="dirty = true" maxlength="200" @disabled($locked)></td>
                                        <td><input class="input !py-1 text-xs" x-model="r.note" @input="dirty = true" maxlength="20" @disabled($locked)></td>
                                        <td><input class="input !py-1 text-right text-sm tabular-nums" x-model="r.cy" x-show="r.kind !== 'heading'" @input="dirty = true" @blur="tidy(r, 'cy')" @disabled($locked)></td>
                                        <td><input class="input !py-1 text-right text-sm tabular-nums" x-model="r.py" x-show="r.kind !== 'heading'" @input="dirty = true" @blur="tidy(r, 'py')" @disabled($locked)></td>
                                        <td class="whitespace-nowrap text-right text-xs">
                                            @unless ($locked)
                                                <button type="button" class="px-1 text-ink-400 hover:text-navy-900" title="Move up" @click="move(i, -1)">↑</button>
                                                <button type="button" class="px-1 text-ink-400 hover:text-navy-900" title="Move down" @click="move(i, 1)">↓</button>
                                                <button type="button" class="px-1 text-ink-400 hover:text-teal-700" title="Insert a row below" @click="add(i)">+</button>
                                                <button type="button" class="px-1 text-ink-400 hover:text-red-700" title="Remove" @click="remove(i)">×</button>
                                            @endunless
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot class="text-sm font-semibold">
                                @if ($k === 'sofp')
                                    <tr><td colspan="4" class="px-4 py-2">Total assets</td><td class="px-4 py-2 text-right tabular-nums" x-text="fmt(total('asset','cy'))"></td><td class="px-4 py-2 text-right tabular-nums" x-text="fmt(total('asset','py'))"></td><td></td></tr>
                                    <tr><td colspan="4" class="px-4 py-2">Total equity and liabilities</td><td class="px-4 py-2 text-right tabular-nums" x-text="fmt(total('el','cy'))"></td><td class="px-4 py-2 text-right tabular-nums" x-text="fmt(total('el','py'))"></td><td></td></tr>
                                    <tr x-show="total('asset','cy') !== total('el','cy') || total('asset','py') !== total('el','py')"><td colspan="7" class="px-4 pb-3 text-xs font-medium text-red-700">The statement does not balance yet.</td></tr>
                                @else
                                    <tr><td colspan="{{ 3 }}" class="px-4 py-2">Sum of lines</td><td class="px-4 py-2 text-right tabular-nums" x-text="fmt(total(null,'cy'))"></td><td class="px-4 py-2 text-right tabular-nums" x-text="fmt(total(null,'py'))"></td><td></td></tr>
                                @endif
                            </tfoot>
                        </table>
                    </div>
                    <p class="px-5 py-3 text-xs text-ink-500">Lines are added up; subtotals are shown as you type them. {{ $k === 'sofp' ? '' : 'Type expenses and outflows in brackets, e.g. (12,500.00).' }}</p>
                </div>
            @endforeach

            <div x-show="tab === 'notes'" x-cloak class="card mt-4" x-data="fsNotes(@js(['notes' => $fs->notes, 'url' => route('portal.fs.notes', $engagement)]))">
                <div class="card-header">
                    <h2 class="card-title">Notes to the financial statements</h2>
                    <div class="flex items-center gap-3 text-xs">
                        <span x-show="dirty" class="text-amber-700">Unsaved changes</span><span x-show="saved" class="text-emerald-700">Saved</span>
                        @unless ($locked)<button type="button" class="btn-secondary btn-sm" @click="add()">+ Note</button><button type="button" class="btn-primary btn-sm" @click="save()" :disabled="saving">Save</button>@endunless
                    </div>
                </div>
                <p x-show="error" x-text="error" class="mx-5 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"></p>
                <div class="divide-y divide-navy-50">
                    <template x-for="(n, i) in notes" :key="i">
                        <div class="space-y-2 px-5 py-4">
                            <div class="flex items-center gap-2">
                                <span class="w-6 text-sm font-semibold text-ink-500" x-text="(i + 1) + '.'"></span>
                                <input class="input !py-1.5 font-medium" x-model="n.title" @input="dirty = true" maxlength="200" @disabled($locked)>
                                @unless ($locked)
                                    <button type="button" class="px-1 text-ink-400 hover:text-navy-900" @click="move(i, -1)">↑</button>
                                    <button type="button" class="px-1 text-ink-400 hover:text-navy-900" @click="move(i, 1)">↓</button>
                                    <button type="button" class="px-1 text-ink-400 hover:text-red-700" @click="remove(i)">×</button>
                                @endunless
                            </div>
                            <textarea class="input text-sm" rows="4" x-model="n.body" @input="dirty = true" @disabled($locked)></textarea>
                        </div>
                    </template>
                </div>
            </div>

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
        </div>
    @endif
</x-layouts.portal>
