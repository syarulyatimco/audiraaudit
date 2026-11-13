<x-layouts.app :title="'Account mapping · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-tb-nav :engagement="$engagement" />
    @php $grouped = $fslis->groupBy(fn ($f) => $f->classification->value); @endphp

    @if ($total === 0)
        <div class="card"><x-empty title="No trial balance yet" description="Import the trial balance first; its accounts appear here for mapping.">
            <a href="{{ route('trial-balance.index', $engagement) }}" class="btn-primary btn-sm">Import trial balance</a></x-empty></div>
    @else
    <div class="grid gap-6 xl:grid-cols-4">
        <div class="space-y-6 xl:col-span-3">
            @include('ai.partials.panel', ['task' => 'suggest_mappings', 'title' => 'AI mapping assistant', 'blurb' => 'The Accounting & TB agent proposes an FSLI for each unmapped account with a confidence and rationale. You accept what you agree with.', 'show' => $unmappedCount > 0 || $aiRun])
            @if ($canMap && count($suggestions))
                <form method="POST" action="{{ route('trial-balance.mapping.accept', $engagement) }}" class="card" x-data="{ all: true }">
                    @csrf
                    <div class="card-header">
                        <div><h2 class="card-title">{{ count($suggestions) }} mapping suggestion(s)</h2><p class="text-xs text-ink-500">From this client's earlier engagements or account-name rules. Nothing is applied until you accept it.</p></div>
                        <button class="btn-accent btn-sm">Accept selected</button>
                    </div>
                    <div class="max-h-72 overflow-y-auto">
                        <table class="table text-sm">
                            <thead><tr><th class="w-8"><input type="checkbox" class="checkbox" checked @change="all = $el.checked; $root.querySelectorAll('.sugg').forEach(c => c.checked = all)"></th><th>Account</th><th>Suggested FSLI</th><th>Basis</th></tr></thead>
                            <tbody>
                            @foreach ($suggestions as $code => $s)
                                <tr>
                                    <td><input type="checkbox" name="codes[]" value="{{ $code }}" class="checkbox sugg" checked></td>
                                    <td><span class="font-mono text-xs">{{ $code }}</span> {{ $accounts[$code]['name'] ?? '' }}</td>
                                    <td>{{ $fslis->firstWhere('id', $s['fsli_id'])?->name }}</td>
                                    <td><span class="badge {{ $s['source'] === 'carried_forward' ? 'badge-teal' : 'badge-neutral' }}">{{ $s['source'] === 'carried_forward' ? 'Carried forward' : 'Rule' }}</span> <span class="text-xs text-ink-500">{{ $s['reason'] }}</span></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </form>
            @endif

            <form method="POST" action="{{ route('trial-balance.mapping.update', $engagement) }}" class="card"
                  x-data @submit="$el.querySelectorAll('select[data-original]').forEach(s => { if (s.value === s.dataset.original) s.disabled = true; })">
                @csrf @method('PUT')
                <div class="card-header">
                    <div class="flex gap-1 text-sm">
                        <a href="{{ route('trial-balance.mapping', $engagement) }}" class="rounded px-2.5 py-1 {{ $filter === 'all' ? 'bg-navy-50 font-semibold text-navy-900' : 'text-ink-500' }}">All ({{ $total }})</a>
                        <a href="{{ route('trial-balance.mapping', [$engagement, 'show' => 'unmapped']) }}" class="rounded px-2.5 py-1 {{ $filter === 'unmapped' ? 'bg-navy-50 font-semibold text-navy-900' : 'text-ink-500' }}">Unmapped ({{ $unmappedCount }})</a>
                    </div>
                    @if ($canMap)<button class="btn-primary btn-sm">Save mapping</button>@endif
                </div>
                <div class="overflow-x-auto">
                    <table class="table text-sm">
                        <thead><tr><th>Account</th><th class="text-right">Current year</th><th class="text-right">Prior year</th><th class="w-80">FSLI</th><th>Source</th></tr></thead>
                        <tbody>
                        @forelse ($accounts as $a)
                            <tr class="{{ $a['fsli_id'] ? '' : 'bg-amber-50/40' }}">
                                <td><span class="font-mono text-xs font-semibold text-navy-800">{{ $a['code'] }}</span> <span class="text-ink-700">{{ $a['name'] }}</span>
                                    @if (! $a['in_cy'])<span class="badge badge-neutral ml-1">PY only</span>@endif</td>
                                <td class="text-right tabular-nums">{{ amount($a['cy']) }}</td>
                                <td class="text-right tabular-nums text-ink-500">{{ amount($a['py']) }}</td>
                                <td>
                                    <select name="map[{{ $a['code'] }}]" data-original="{{ $a['fsli_id'] }}" class="input py-1 text-xs" @disabled(! $canMap)>
                                        <option value="">— Unmapped —</option>
                                        @foreach (\App\Enums\FsliClassification::cases() as $class)
                                            @if ($grouped->has($class->value))
                                                <optgroup label="{{ $class->label() }}">
                                                    @foreach ($grouped[$class->value] as $f)<option value="{{ $f->id }}" @selected($a['fsli_id'] === $f->id)>{{ $f->name }}</option>@endforeach
                                                </optgroup>
                                            @endif
                                        @endforeach
                                    </select>
                                </td>
                                <td class="text-xs text-ink-500">{{ $a['source'] ? \App\Enums\MappingSource::from($a['source'])->label() : '' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-empty title="Everything is mapped" /></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($canMap)<div class="flex justify-end border-t border-navy-100/70 px-5 py-3"><button class="btn-primary">Save mapping</button></div>@endif
            </form>
        </div>

        <div class="card h-max" x-data="{ adding: false }">
            <div class="card-header"><h2 class="card-title">FSLIs</h2>@if($canManageFslis)<button type="button" class="btn-ghost btn-sm" @click="adding = !adding">Add</button>@endif</div>
            @if ($canManageFslis)
                <form x-show="adding" x-cloak method="POST" action="{{ route('fslis.store', $engagement) }}" class="space-y-2 border-b border-navy-50 px-5 py-3">
                    @csrf
                    <input name="code" class="input py-1.5 text-xs" placeholder="Code (e.g. BIO)" required>
                    <input name="name" class="input py-1.5 text-xs" placeholder="Name" required>
                    <select name="classification" class="input py-1.5 text-xs" required>@foreach (\App\Enums\FsliClassification::options() as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                    <button class="btn-primary btn-sm">Add FSLI</button>
                </form>
            @endif
            <div class="max-h-[70vh] overflow-y-auto">
                @foreach (\App\Enums\FsliClassification::cases() as $class)
                    @continue(! $grouped->has($class->value))
                    <div class="px-5 pb-1 pt-3 text-2xs font-semibold uppercase tracking-wider text-ink-400">{{ $class->label() }}</div>
                    @foreach ($grouped[$class->value] as $f)
                        <div class="flex items-center justify-between gap-2 px-5 py-1.5 text-sm" x-data="{ edit: false }">
                            <span x-show="!edit" class="min-w-0 truncate"><a href="{{ route('lead-schedules.show', [$engagement, $f]) }}" class="text-navy-900 hover:text-teal-700">{{ $f->name }}</a> <span class="text-xs text-ink-400">{{ $f->mappings_count }}</span></span>
                            @if ($canManageFslis)
                                <form x-show="edit" x-cloak method="POST" action="{{ route('fslis.update', [$engagement, $f]) }}" class="flex flex-1 gap-1">@csrf @method('PUT')<input name="name" value="{{ $f->name }}" class="input py-1 text-xs"><button class="btn-secondary btn-sm">Save</button></form>
                                <span x-show="!edit" class="flex shrink-0 gap-1">
                                    <button type="button" class="text-xs text-ink-400 hover:text-navy-900" @click="edit = true">Rename</button>
                                    @if ($f->mappings_count === 0)<form method="POST" action="{{ route('fslis.destroy', [$engagement, $f]) }}">@csrf @method('DELETE')<button class="text-xs text-ink-400 hover:text-red-700">Delete</button></form>@endif
                                </span>
                            @endif
                        </div>
                    @endforeach
                @endforeach
            </div>
        </div>
    </div>
    @endif
</x-layouts.app>
