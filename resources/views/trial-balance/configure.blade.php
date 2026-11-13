<x-layouts.app title="Map trial balance columns">
    <x-engagement-nav :engagement="$engagement" />
    <x-tb-nav :engagement="$engagement" />
    <form method="POST" action="{{ route('trial-balance.activate', [$engagement, $import]) }}" class="space-y-6"
          x-data="{ mode: '{{ old('mode', $map['mode'] ?? 'debit_credit') }}', header: {{ (int) old('header_row', $map['header_row'] ?? 1) }},
                    cols: { code: '{{ old('code', $map['code'] ?? '') }}', name: '{{ old('name', $map['name'] ?? '') }}', debit: '{{ old('debit', $map['debit'] ?? '') }}', credit: '{{ old('credit', $map['credit'] ?? '') }}', balance: '{{ old('balance', $map['balance'] ?? '') }}' },
                    roleOf(i) { for (const [k, v] of Object.entries(this.cols)) { if (v !== '' && Number(v) === i && (k !== 'balance' || this.mode === 'balance') && (!['debit','credit'].includes(k) || this.mode === 'debit_credit')) return k; } return null; } }">
        @csrf
        <div class="card">
            <div class="card-header"><div><h2 class="card-title">{{ $import->period->label() }} — confirm columns</h2><p class="text-xs text-ink-500">{{ number_format($totalRows) }} rows read from {{ $import->document->title }}. Columns were detected automatically; check them before importing.</p></div>
            </div>
            <div class="card-body grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <div><label class="label">Header row</label><input type="number" name="header_row" min="1" class="input" x-model.number="header" required></div>
                <div><label class="label">Amounts</label><select name="mode" class="input" x-model="mode"><option value="debit_credit">Debit &amp; credit columns</option><option value="balance">Single signed balance</option></select></div>
                @foreach (['code' => 'Account code', 'name' => 'Account name'] as $k => $l)
                    <div><label class="label">{{ $l }}</label><select name="{{ $k }}" class="input" x-model="cols.{{ $k }}" required><option value="">—</option>@for ($i = 0; $i < $width; $i++)<option value="{{ $i }}">Column {{ column_letter($i) }}</option>@endfor</select></div>
                @endforeach
                <div x-show="mode === 'debit_credit'"><label class="label">Debit</label><select name="debit" class="input" x-model="cols.debit" :disabled="mode !== 'debit_credit'"><option value="">—</option>@for ($i = 0; $i < $width; $i++)<option value="{{ $i }}">Column {{ column_letter($i) }}</option>@endfor</select></div>
                <div x-show="mode === 'debit_credit'"><label class="label">Credit</label><select name="credit" class="input" x-model="cols.credit" :disabled="mode !== 'debit_credit'"><option value="">—</option>@for ($i = 0; $i < $width; $i++)<option value="{{ $i }}">Column {{ column_letter($i) }}</option>@endfor</select></div>
                <div x-show="mode === 'balance'" x-cloak><label class="label">Balance</label><select name="balance" class="input" x-model="cols.balance" :disabled="mode !== 'balance'"><option value="">—</option>@for ($i = 0; $i < $width; $i++)<option value="{{ $i }}">Column {{ column_letter($i) }}</option>@endfor</select>
                    <p class="hint">Debits positive, credits negative (or “CR” / brackets).</p></div>
            </div>
            <div class="flex items-center justify-between border-t border-navy-100/70 px-5 py-3">
                <p class="text-xs text-ink-500">Rows without an account code are skipped (reported as warnings). The import is rejected unless debits equal credits to the sen.</p>
                <button class="btn-primary">Validate and import</button>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <div class="card-header"><h2 class="card-title">Preview (first {{ count($preview) }} rows)</h2></div>
            <table class="table text-xs">
                <thead><tr><th class="w-12">Row</th>@for ($i = 0; $i < $width; $i++)<th :class="roleOf({{ $i }}) && '!bg-teal-50 !text-teal-800'">{{ column_letter($i) }} <span x-text="roleOf({{ $i }}) ? '· ' + roleOf({{ $i }}) : ''"></span></th>@endfor</tr></thead>
                <tbody>
                @foreach ($preview as $idx => $row)
                    <tr :class="{{ $idx + 1 }} === header ? 'bg-navy-50 font-semibold' : ({{ $idx + 1 }} < header ? 'opacity-40' : '')">
                        <td class="tabular-nums text-ink-400">{{ $idx + 1 }}</td>
                        @for ($i = 0; $i < $width; $i++)<td class="whitespace-nowrap">{{ $row[$i] ?? '' }}</td>@endfor
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </form>
    <form method="POST" action="{{ route('trial-balance.discard', [$engagement, $import]) }}" class="mt-4">@csrf<button class="btn-ghost btn-sm text-red-700">Discard this import</button></form>
</x-layouts.app>
