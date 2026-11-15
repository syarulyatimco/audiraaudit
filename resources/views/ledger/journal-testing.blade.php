<x-layouts.app :title="'Journal entry testing · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-tb-nav :engagement="$engagement" />
    @php $v = fn ($k, $d = '') => old($k, $input[$k] ?? $d); @endphp

    @if (! $gl)
        <div class="card"><x-empty title="Import the general ledger first" description="Journal entry testing runs over the imported GL postings." /></div>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            <form method="GET" class="card lg:col-span-1">
                <div class="card-header"><div><h2 class="card-title">Criteria</h2><p class="text-xs text-ink-500">Choose the characteristics the team considers relevant to the risk of management override. An entry is selected when it meets at least the minimum number of criteria.</p></div></div>
                <div class="card-body space-y-3 text-sm">
                    <label class="flex items-center gap-2"><input type="checkbox" name="weekend" value="1" class="checkbox" @checked($criteria['weekend'])> {{ $labels['weekend'] }}</label>
                    <div><label class="label">Other dates to flag (e.g. public holidays)</label><input name="dates" value="{{ $v('dates') }}" class="input" placeholder="31/08/2025, 16/09/2025"></div>
                    <div><label class="label">Days either side of the period end ({{ $engagement->period_end->format('j M Y') }})</label><input type="number" min="0" max="60" name="year_end_days" value="{{ $v('year_end_days') }}" class="input"></div>
                    <div><label class="label">Round amounts: multiples of RM</label><input type="number" min="0" name="round_rm" value="{{ $v('round_rm') }}" class="input" placeholder="e.g. 1000"></div>
                    <div><label class="label">Amount at or above (RM)</label><input name="threshold_rm" value="{{ $v('threshold_rm') }}" class="input" placeholder="{{ $pm ? 'Performance materiality: '.amount($pm, false) : '' }}"></div>
                    <div><label class="label">Users to flag</label><input name="users" value="{{ $v('users') }}" class="input" placeholder="comma separated">
                        @if ($users->isNotEmpty())<p class="hint">Users in this GL (fewest postings first): {{ $users->map(fn ($u) => $u->posted_by.' ('.$u->n.')')->join(', ') }}</p>@endif</div>
                    <div><label class="label">Users with at most this many postings</label><input type="number" min="0" max="100" name="rare_users_max" value="{{ $v('rare_users_max') }}" class="input"></div>
                    <div><label class="label">Journal / source types to flag (e.g. manual journals)</label><input name="journal_types" value="{{ $v('journal_types') }}" class="input" placeholder="JV, GJ">
                        @if ($types->isNotEmpty())<p class="hint">Types in this GL: {{ $types->map(fn ($t) => $t->journal_type.' ('.$t->n.')')->join(', ') }}</p>@endif</div>
                    <div><label class="label">Description keywords</label><input name="keywords" value="{{ $v('keywords') }}" class="input" placeholder="adjust, reverse, write off, director, suspense"></div>
                    <div><label class="label">Accounts to flag</label><input name="accounts" value="{{ $v('accounts') }}" class="input" placeholder="account codes"></div>
                    <div><label class="label">Minimum criteria met</label><input type="number" min="1" max="10" name="min_matches" value="{{ $criteria['min_matches'] }}" class="input"></div>
                    <button class="btn-primary w-full">Find entries</button>
                </div>
            </form>

            <div class="space-y-6 lg:col-span-2">
                @if (! $result)
                    <div class="card"><x-empty title="Choose criteria and find entries" description="Results show each entry's matching criteria, largest and most-flagged first." /></div>
                @else
                    <div class="card overflow-x-auto">
                        <div class="card-header">
                            <div><h2 class="card-title">{{ number_format($result['total']) }} entr{{ $result['total'] === 1 ? 'y' : 'ies' }} selected</h2><p class="text-xs text-ink-500">{{ count($result['enabled']) }} criteria enabled · showing up to 300</p></div>
                        </div>
                        <table class="table text-sm">
                            <thead><tr><th>Date</th><th>Account</th><th>Ref / description</th><th>User</th><th class="text-right">Amount</th><th>Criteria</th></tr></thead>
                            <tbody>
                            @forelse ($result['rows'] as $r)
                                <tr><td class="whitespace-nowrap">{{ \Carbon\Carbon::parse($r->posted_on)->format('d/m/Y') }}</td><td class="font-mono text-xs">{{ $r->account_code }}</td>
                                    <td><span class="text-xs text-ink-500">{{ $r->reference }}</span> {{ \Illuminate\Support\Str::limit($r->description, 60) }}</td><td class="text-xs">{{ $r->posted_by }}</td>
                                    <td class="text-right tabular-nums">{{ amount($r->amount_sen) }}</td>
                                    <td class="text-2xs">@foreach ($r->reasons as $k)<span class="mr-1 inline-block rounded bg-amber-50 px-1.5 py-0.5 text-amber-800">{{ $labels[$k] }}</span>@endforeach</td></tr>
                            @empty
                                <tr><td colspan="6" class="px-5 py-6 text-center text-ink-500">No entries meet these criteria.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if ($canSave && $result['total'] > 0)
                        <form method="POST" action="{{ route('ledger.jet.save', $engagement) }}" class="card card-body flex flex-wrap items-end gap-3">
                            @csrf
                            @foreach (['weekend', 'dates', 'year_end_days', 'round_rm', 'threshold_rm', 'users', 'rare_users_max', 'journal_types', 'keywords', 'accounts', 'min_matches'] as $k)<input type="hidden" name="{{ $k }}" value="{{ $input[$k] ?? '' }}">@endforeach
                            <div class="min-w-[16rem] flex-1"><label class="label">Save as a journal entry test</label><input name="title" class="input" required maxlength="200" value="Journal entries — management override"></div>
                            <button class="btn-primary">Save {{ min($result['total'], \App\Services\Ledger\JournalTestingService::MAX_ITEMS) }} entries</button>
                            @error('jet')<p class="error w-full">{{ $message }}</p>@enderror
                            @error('title')<p class="error w-full">{{ $message }}</p>@enderror
                        </form>
                    @endif
                @endif
            </div>
        </div>
    @endif
</x-layouts.app>
