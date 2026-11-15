<x-layouts.app :title="'Check ageing columns · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-tb-nav :engagement="$engagement" />
    @php $map = $report->column_map; $head = $report->preview[0] ?? []; $guess = collect($report->buckets)->keyBy('col'); @endphp
    <form method="POST" action="{{ route('ageing.activate', [$engagement, $report]) }}" class="space-y-6">
        @csrf
        <div class="card">
            <div class="card-header"><div><h2 class="card-title">{{ $report->label() }} — check the columns</h2><p class="text-xs text-ink-500">Tick the columns that hold ageing buckets and give each the age it starts from (in days). The buckets must add up to the total on every row.</p></div></div>
            @error('map')<div class="mx-5 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">@foreach ((array) $errors->get('map') as $m)<div>{{ is_array($m) ? implode(' ', $m) : $m }}</div>@endforeach</div>@enderror
            <div class="card-body grid gap-3 sm:grid-cols-4">
                <div><label class="label">Header row</label><input type="number" name="header_row" min="1" value="{{ $report->header_row }}" class="input"></div>
                @foreach (['code' => 'Customer / supplier code', 'name' => 'Name', 'total' => 'Total (optional)'] as $f => $label)
                    <div><label class="label">{{ $label }}</label><select name="{{ $f }}" class="input"><option value="">— none —</option>@foreach ($head as $i => $h)<option value="{{ $i }}" @selected(($map[$f] ?? null) === $i)>{{ \App\Support\Spreadsheets\XlsxWriter::column($i) }} · {{ \Illuminate\Support\Str::limit($h, 25) ?: '(blank)' }}</option>@endforeach</select></div>
                @endforeach
            </div>
            <div class="overflow-x-auto px-5 pb-5">
                <table class="table text-sm">
                    <thead><tr><th>Use</th><th>Column</th><th>Label</th><th>From (days)</th></tr></thead>
                    <tbody>
                    @foreach ($head as $i => $h)
                        @continue(in_array($i, $map, true))
                        <tr><td><input type="hidden" name="buckets[{{ $i }}][col]" value="{{ $i }}"><input type="checkbox" name="buckets[{{ $i }}][use]" value="1" class="checkbox" @checked($guess->has($i))></td>
                            <td class="text-xs">{{ \App\Support\Spreadsheets\XlsxWriter::column($i) }} · {{ $h ?: '(blank)' }}</td>
                            <td><input name="buckets[{{ $i }}][label]" value="{{ $guess[$i]['label'] ?? $h }}" class="input !py-1 text-xs" maxlength="40"></td>
                            <td><input type="number" min="0" name="buckets[{{ $i }}][min_days]" value="{{ $guess[$i]['min_days'] ?? '' }}" class="input !py-1 w-24 text-xs"></td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card overflow-x-auto">
            <div class="card-header"><h2 class="card-title">Preview</h2></div>
            <table class="table text-xs"><tbody>
                @foreach ($report->preview as $n => $row)<tr class="{{ $n === 0 ? 'bg-navy-50 font-semibold' : '' }}">@foreach ($row as $c)<td class="whitespace-nowrap">{{ \Illuminate\Support\Str::limit($c, 30) }}</td>@endforeach</tr>@endforeach
            </tbody></table>
        </div>
        <div class="flex justify-between">
            <button formaction="{{ route('ageing.discard', [$engagement, $report]) }}" class="btn-ghost">Discard</button>
            <button class="btn-primary">Import listing</button>
        </div>
    </form>
</x-layouts.app>
