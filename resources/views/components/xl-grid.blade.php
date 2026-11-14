{{-- Excel-style grid. $config is passed to the xlGrid Alpine component (public/js/app.js). --}}
@props(['config', 'signoffs' => false, 'canReview' => false, 'userId' => 0, 'showGuidance' => true])
<div x-data="xlGrid(@js($config))" class="card">
    <div class="card-header flex-wrap">
        <div class="flex items-center gap-3 text-xs text-ink-500">
            <span x-show="status === 'saving'" class="text-amber-700">Saving…</span>
            <span x-show="status === 'saved'" class="text-emerald-700">All changes saved</span>
            <span x-show="status === 'error'" class="text-red-700">Last change not saved</span>
            @if ($signoffs)
                <span class="text-ink-300">|</span>
                <span x-text="`${progress.n} steps · ${progress.done} done · ${progress.reviewed} reviewed`"></span>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            {{ $toolbar ?? '' }}
            @if ($config['canManage'])
                <button type="button" class="btn-secondary btn-sm" @click="addRow(sel ? rows[sel.r]?.uuid : null)">+ Row<span x-show="sel" class="text-ink-400"> below</span></button>
                @if (! empty($config['urls']['columns']))
                    <button type="button" class="btn-secondary btn-sm" @click="addColumn()">+ Column</button>
                @endif
            @endif
        </div>
    </div>
    <div x-ref="grid" tabindex="0" class="xl-wrap" @keydown="key($event)" @paste="paste($event)">
        <table class="xl">
            <thead>
                <tr>
                    <th class="xl-rownum">#</th>
                    <template x-for="(col, c) in allColumns" :key="col.key">
                        <th :style="col.width ? `min-width:${col.width}` : 'min-width:8rem'">
                            <div class="flex items-center justify-between gap-1">
                                <span x-text="col.label"></span>
                                <template x-if="col.custom && {{ $config['canManage'] ? 'true' : 'false' }}">
                                    <span class="flex gap-0.5 normal-case">
                                        <button type="button" class="rounded px-1 text-ink-400 hover:bg-white hover:text-navy-900" title="Rename column" @click.stop="renameColumn(col)">✎</button>
                                        <button type="button" class="rounded px-1 text-ink-400 hover:bg-white hover:text-red-700" title="Remove column" @click.stop="removeColumn(col)">×</button>
                                    </span>
                                </template>
                            </div>
                        </th>
                    </template>
                    @if ($signoffs)<th style="min-width:13rem">Sign-off</th>@endif
                    <th class="w-10"></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(row, r) in rows" :key="row.uuid">
                    <tr :id="'row-' + row.uuid" :class="{ 'xl-signed': row.done_by_id, 'xl-target': location.hash === '#row-' + row.uuid }">
                        <td class="xl-rownum" x-text="r + 1"></td>
                        <template x-for="(col, c) in allColumns" :key="col.key">
                            <td class="xl-cell" :data-cell="r + '-' + c" :class="{ 'xl-sel': isSel(r, c), 'xl-locked': ! col.editable }"
                                @click="select(r, c)" @dblclick="select(r, c); begin()" :title="col.key === 'procedure' && row.guidance ? 'Guidance: ' + row.guidance : null">
                                <div class="xl-text" x-html="display(row, col)"></div>
                                <template x-if="col.key === 'procedure' && row.isa_refs">
                                    <div class="mt-1 text-2xs font-medium text-teal-700" x-text="row.isa_refs"></div>
                                </template>
                                <template x-if="col.key === 'procedure' && row.guidance && {{ ($showGuidance ?? true) ? 'true' : 'false' }}">
                                    <div class="mt-0.5 text-2xs italic text-ink-400" x-text="row.guidance"></div>
                                </template>
                                <template x-if="editing && isSel(r, c)">
                                    <textarea id="xl-editor" class="xl-editor" x-model="draft" rows="1" @input="autosize($event.target)" @blur="commit()" @keydown.stop="key($event)" @click.stop></textarea>
                                </template>
                            </td>
                        </template>
                        @if ($signoffs)
                            <td class="text-xs">
                                <template x-if="row.done_by_id">
                                    <div class="space-y-0.5">
                                        <div class="text-emerald-700">✓ Done · <span x-text="row.done_by"></span></div>
                                        <template x-if="row.reviewed_by_id"><div class="text-navy-800">✓ Reviewed · <span x-text="row.reviewed_by"></span> <span class="text-ink-400" x-text="row.reviewed_at"></span></div></template>
                                    </div>
                                </template>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @if ($config['canEdit'])
                                        <button type="button" x-show="! row.done_by_id" class="btn-secondary btn-sm !px-2 !py-0.5" @click="signoff(row, 'done')">Sign done</button>
                                    @endif
                                    @if ($canReview ?? false)
                                        <button type="button" x-show="row.done_by_id && ! row.reviewed_by_id && row.done_by_id !== {{ (int) ($userId ?? 0) }}" class="btn-accent btn-sm !px-2 !py-0.5" @click="signoff(row, 'review')">Review</button>
                                    @endif
                                    @if ($config['canEdit'])
                                        <button type="button" x-show="(row.done_by_id === {{ (int) ($userId ?? 0) }} && ! row.reviewed_by_id) || row.reviewed_by_id === {{ (int) ($userId ?? 0) }}" class="btn-ghost btn-sm !px-1.5 !py-0.5 text-2xs" @click="signoff(row, 'unsign')">Withdraw</button>
                                    @endif
                                </div>
                            </td>
                        @endif
                        <td class="relative text-right">
                            <button type="button" class="rounded px-1.5 py-0.5 text-ink-400 hover:bg-navy-50 hover:text-navy-900" @click.stop="menu = menu === row.uuid ? null : row.uuid" title="Row actions">⋯</button>
                            <div x-cloak x-show="menu === row.uuid" @click.outside="menu = null" class="absolute right-2 top-7 z-40 w-48 rounded-md border border-navy-100 bg-white py-1 text-left text-xs shadow-lg">
                                <template x-if="row.token">
                                    <button type="button" class="block w-full px-3 py-1.5 text-left hover:bg-navy-50" @click="copyLink(row)">Copy as link</button>
                                </template>
                                @if ($config['canManage'])
                                    <button type="button" class="block w-full px-3 py-1.5 text-left hover:bg-navy-50" @click="addRow(row.uuid)">Insert row below</button>
                                    <button type="button" class="block w-full px-3 py-1.5 text-left hover:bg-navy-50" @click="moveRow(row, -1)">Move up</button>
                                    <button type="button" class="block w-full px-3 py-1.5 text-left hover:bg-navy-50" @click="moveRow(row, 1)">Move down</button>
                                    <button type="button" class="block w-full px-3 py-1.5 text-left text-red-700 hover:bg-red-50" x-show="! row.done_by_id" @click="removeRow(row)">Delete row</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                </template>
                <tr x-show="rows.length === 0"><td :colspan="allColumns.length + 3" class="px-4 py-8 text-center text-sm text-ink-500">No rows yet.@if ($config['canManage']) Use “+ Row” to start.@endif</td></tr>
            </tbody>
        </table>
    </div>
    <div class="flex items-center justify-between border-t border-navy-100/70 px-5 py-2 text-2xs text-ink-400">
        <span>Click a cell and type to edit · Enter saves · Alt+Enter new line · Esc cancels · Ctrl/⌘+V pastes a block from Excel</span>
    </div>
    <div x-cloak x-show="flash" x-transition class="fixed bottom-6 right-6 z-50 max-w-sm rounded-md px-4 py-3 text-sm shadow-lg" :class="flash?.isError ? 'bg-red-700 text-white' : 'bg-navy-900 text-white'" x-text="flash?.msg"></div>
    {{ $after ?? '' }}
</div>
