<x-layouts.app :title="'Check GL columns · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-tb-nav :engagement="$engagement" />
    @php $map = $import->column_map; $head = $import->preview[0] ?? []; @endphp
    <form method="POST" action="{{ route('ledger.activate', [$engagement, $import]) }}" class="space-y-6">
        @csrf
        <div class="card">
            <div class="card-header"><div><h2 class="card-title">Check the columns</h2><p class="text-xs text-ink-500">AUDIRA matched the headings below. Correct any that are wrong. Rows called "balance b/f" or "opening balance" are treated as opening balances.</p></div></div>
            @error('map')<div class="mx-5 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">@foreach ((array) $errors->get('map') as $m)<div>{{ is_array($m) ? implode(' ', $m) : $m }}</div>@endforeach</div>@enderror
            <div class="card-body grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div><label class="label">Header row</label><input type="number" name="header_row" min="1" value="{{ $import->header_row }}" class="input"></div>
                @foreach ($fields as $f => $label)
                    <div><label class="label">{{ $label }}</label>
                        <select name="{{ $f }}" class="input"><option value="">— none —</option>@foreach ($head as $i => $h)<option value="{{ $i }}" @selected(($map[$f] ?? null) === $i)>{{ \App\Support\Spreadsheets\XlsxWriter::column($i) }} · {{ \Illuminate\Support\Str::limit($h, 30) ?: '(blank)' }}</option>@endforeach</select></div>
                @endforeach
            </div>
        </div>
        <div class="card overflow-x-auto">
            <div class="card-header"><h2 class="card-title">Preview</h2><span class="text-xs text-ink-500">first rows from the header row</span></div>
            <table class="table text-xs"><tbody>
                @foreach ($import->preview as $n => $row)
                    <tr class="{{ $n === 0 ? 'bg-navy-50 font-semibold' : '' }}">@foreach ($row as $c)<td class="whitespace-nowrap">{{ \Illuminate\Support\Str::limit($c, 40) }}</td>@endforeach</tr>
                @endforeach
            </tbody></table>
        </div>
        <div class="flex justify-between">
            <button formaction="{{ route('ledger.discard', [$engagement, $import]) }}" class="btn-ghost">Discard</button>
            <button class="btn-primary">Import and reconcile</button>
        </div>
    </form>
</x-layouts.app>
