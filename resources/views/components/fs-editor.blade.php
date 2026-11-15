{{-- Statement and notes editors for management's financial statements (portal and audit team). Parent sets `tab`. --}}
@props(['fs', 'statementUrl', 'notesUrl', 'locked' => false])
    @foreach (\App\Models\ClientFinancialStatement::STATEMENTS as $k)
        <div x-show="tab === '{{ $k }}'" x-cloak class="card mt-4" x-data="fsEditor(@js(['key' => $k, 'rows' => $fs->statements[$k]['rows'], 'url' => str_replace('__KEY__', $k, $statementUrl)]))">
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
            @unless ($locked)
                <div class="flex flex-wrap items-center gap-2 border-t border-navy-50 px-5 pt-3">
                    <button type="button" class="btn-secondary btn-sm" @click="add(rows.length - 1)">+ Add row</button>
                    <button type="button" class="btn-ghost btn-sm" @click="add(rows.length - 1, 'heading')">+ Heading</button>
                    <button type="button" class="btn-ghost btn-sm" @click="add(rows.length - 1, 'subtotal')">+ Subtotal</button>
                </div>
            @endunless
            <p class="px-5 py-3 text-xs text-ink-500">Lines are added up; subtotals are shown as you type them. {{ $k === 'sofp' ? '' : 'Type expenses and outflows in brackets, e.g. (12,500.00).' }}</p>
        </div>
    @endforeach

    <div x-show="tab === 'notes'" x-cloak class="card mt-4" x-data="fsNotes(@js(['notes' => $fs->notes, 'url' => $notesUrl]))">
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
