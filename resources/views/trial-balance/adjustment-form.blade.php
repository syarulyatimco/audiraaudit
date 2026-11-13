@php
    $editing = (bool) $adjustment;
    $initial = old('lines') ?? ($editing
        ? $adjustment->lines->map(fn ($l) => ['account_code' => $l->account_code, 'description' => $l->description, 'debit' => $l->debit_sen ? number_format($l->debit_sen / 100, 2, '.', '') : '', 'credit' => $l->credit_sen ? number_format($l->credit_sen / 100, 2, '.', '') : ''])->all()
        : [['account_code' => '', 'description' => '', 'debit' => '', 'credit' => ''], ['account_code' => '', 'description' => '', 'debit' => '', 'credit' => '']]);
@endphp
<x-layouts.app :title="$editing ? 'Edit '.$adjustment->number : 'New adjustment'">
    <x-engagement-nav :engagement="$engagement" />
    <x-tb-nav :engagement="$engagement" />
    @if ($accounts->isEmpty())
        <div class="card"><x-empty title="No current-year trial balance" description="Import the trial balance before recording adjustments." /></div>
    @else
    <form method="POST" action="{{ $editing ? route('adjustments.update', [$engagement, $adjustment]) : route('adjustments.store', $engagement) }}" class="card"
          x-data="{ lines: @js(array_values($initial)), names: @js($accounts->map(fn ($a) => $a['name'])),
                    num(v) { const s = String(v || '').replace(/[,\s]/g, ''); const n = parseFloat(s); return isNaN(n) ? 0 : Math.round(n * 100); },
                    get dr() { return this.lines.reduce((t, l) => t + this.num(l.debit), 0) }, get cr() { return this.lines.reduce((t, l) => t + this.num(l.credit), 0) },
                    fmt(c) { return (c / 100).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) } }">
        @csrf @if ($editing) @method('PUT') @endif
        <div class="card-header"><h2 class="card-title">{{ $editing ? 'Edit '.$adjustment->number : 'New adjustment' }}</h2></div>
        <div class="card-body grid gap-5 sm:grid-cols-3">
            @unless ($editing)
                <x-select name="type" label="Type" :options="\App\Enums\AdjustmentType::options()" value="audit" required />
            @endunless
            <x-field :class="$editing ? 'sm:col-span-3' : 'sm:col-span-2'" name="description" label="Description / basis" type="textarea" rows="2" :value="$adjustment?->description" required hint="Explain the misstatement and reference the working paper." />
        </div>
        <datalist id="tb-accounts">@foreach ($accounts as $code => $a)<option value="{{ $code }}">{{ $a['name'] }}</option>@endforeach</datalist>
        <div class="overflow-x-auto border-t border-navy-100/70">
            <table class="table text-sm">
                <thead><tr><th class="w-48">Account code</th><th>Account</th><th>Line note</th><th class="w-36 text-right">Debit (RM)</th><th class="w-36 text-right">Credit (RM)</th><th class="w-10"></th></tr></thead>
                <tbody>
                <template x-for="(line, i) in lines" :key="i">
                    <tr>
                        <td><input list="tb-accounts" class="input py-1 font-mono text-xs" :name="`lines[${i}][account_code]`" x-model="line.account_code"></td>
                        <td class="text-xs text-ink-600" x-text="names[line.account_code] || (line.account_code ? 'Not in current TB' : '')" :class="line.account_code && !names[line.account_code] && 'text-red-700'"></td>
                        <td><input class="input py-1 text-xs" :name="`lines[${i}][description]`" x-model="line.description"></td>
                        <td><input class="input py-1 text-right text-xs tabular-nums" inputmode="decimal" :name="`lines[${i}][debit]`" x-model="line.debit" @input="if (line.debit) line.credit = ''"></td>
                        <td><input class="input py-1 text-right text-xs tabular-nums" inputmode="decimal" :name="`lines[${i}][credit]`" x-model="line.credit" @input="if (line.credit) line.debit = ''"></td>
                        <td><button type="button" class="text-ink-400 hover:text-red-700" @click="lines.splice(i, 1)" x-show="lines.length > 2" aria-label="Remove line">&times;</button></td>
                    </tr>
                </template>
                </tbody>
                <tfoot><tr class="font-semibold">
                    <td class="px-4 py-3" colspan="3"><button type="button" class="btn-ghost btn-sm" @click="lines.push({ account_code: '', description: '', debit: '', credit: '' })">+ Add line</button></td>
                    <td class="px-4 py-3 text-right tabular-nums" x-text="fmt(dr)"></td>
                    <td class="px-4 py-3 text-right tabular-nums" x-text="fmt(cr)"></td>
                    <td></td>
                </tr></tfoot>
            </table>
        </div>
        <div class="flex items-center justify-between border-t border-navy-100/70 px-5 py-3">
            <span class="text-sm" :class="dr === cr && dr > 0 ? 'text-emerald-700' : 'text-red-700'" x-text="dr === cr && dr > 0 ? 'Balanced' : 'Out of balance by ' + fmt(Math.abs(dr - cr))"></span>
            <div class="flex gap-2"><a href="{{ route('adjustments.index', $engagement) }}" class="btn-secondary">Cancel</a><button class="btn-primary" :disabled="dr !== cr || dr === 0">Save draft</button></div>
        </div>
    </form>
    @endif
</x-layouts.app>
