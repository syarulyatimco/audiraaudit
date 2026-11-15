<x-layouts.app :title="$test->reference.' · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-tb-nav :engagement="$engagement" />
    @php $done = $items->whereNotNull('result')->count(); $exc = $items->where('result', 'exception')->count(); @endphp
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <a href="{{ route('ledger.index', $engagement) }}" class="text-sm text-ink-500 hover:text-navy-900">← General ledger</a>
        <span class="font-mono text-sm font-semibold text-navy-800">{{ $test->reference }}</span><span class="text-sm font-medium text-navy-900">{{ $test->title }}</span>
        <span class="badge {{ $test->status === 'concluded' ? 'badge-success' : 'badge-info' }}">{{ ucfirst($test->status) }}</span>
        <span class="text-xs text-ink-500">{{ $done }} / {{ $items->count() }} tested · {{ $exc }} exception(s)</span>
    </div>
    @php $c = $test->criteria; @endphp
    <p class="mb-4 text-xs text-ink-500"><span class="font-semibold text-navy-900">Criteria:</span>
        {{ collect([
            $c['weekend'] ? 'weekend postings' : null, $c['dates'] ? 'dates '.implode(', ', $c['dates']) : null, $c['year_end_days'] ? "±{$c['year_end_days']} days of period end" : null,
            $c['round_rm'] ? 'multiples of RM'.number_format($c['round_rm']) : null, $c['threshold_rm'] ? '≥ RM'.number_format($c['threshold_rm'], 2) : null,
            $c['users'] ? 'users '.implode(', ', $c['users']) : null, $c['rare_users_max'] ? "users with ≤ {$c['rare_users_max']} postings" : null,
            $c['journal_types'] ? 'types '.implode(', ', $c['journal_types']) : null, $c['keywords'] ? 'keywords '.implode(', ', $c['keywords']) : null, $c['accounts'] ? 'accounts '.implode(', ', $c['accounts']) : null,
        ])->filter()->join(' · ') }} · at least {{ $c['min_matches'] }} criteria met</p>
    @foreach (['result', 'note', 'evidence', 'item', 'conclusion'] as $k)@error($k)<p class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror @endforeach

    <div class="card overflow-x-auto">
        <table class="table text-sm">
            <thead><tr><th>Date</th><th>Account</th><th>Ref / description</th><th class="text-right">Amount</th><th>Criteria</th><th style="min-width:22rem">Result</th></tr></thead>
            <tbody>
            @foreach ($items as $it)
                @php $l = $it->line; @endphp
                <tr>
                    <td class="whitespace-nowrap">{{ $l->posted_on->format('d/m/Y') }}</td><td class="font-mono text-xs">{{ $l->account_code }}</td>
                    <td><span class="text-xs text-ink-500">{{ $l->reference }}</span> {{ \Illuminate\Support\Str::limit($l->description, 50) }}<div class="text-2xs text-ink-400">{{ $l->posted_by }} {{ $l->journal_type ? '· '.$l->journal_type : '' }}</div></td>
                    <td class="text-right tabular-nums">{{ amount($l->amount_sen) }}</td>
                    <td class="text-2xs">@foreach ($it->reasons as $k)<span class="mr-1 inline-block rounded bg-amber-50 px-1.5 py-0.5 text-amber-800">{{ $labels[$k] ?? $k }}</span>@endforeach</td>
                    <td>
                        @if ($it->result)
                            <div class="{{ $it->result === 'exception' ? 'text-red-700' : 'text-emerald-700' }} text-xs font-medium">{{ $it->result === 'exception' ? '✕ Exception' : '✓ No exception' }} · {{ $it->tester?->name }}</div>
                            @if ($it->note)<div class="text-xs text-ink-600">{{ $it->note }}</div>@endif
                            @if ($it->evidence)<a href="{{ route('documents.show', [$engagement, $it->evidence]) }}" class="link-chip">📎 {{ \Illuminate\Support\Str::limit($it->evidence->title, 30) }}</a>@endif
                        @endif
                        @if ($canTest)
                            <details class="mt-1 text-xs" @if(! $it->result) open @endif>
                                <summary class="cursor-pointer text-ink-500">{{ $it->result ? 'Change' : 'Record result' }}</summary>
                                <form method="POST" action="{{ route('ledger.jet.item', [$engagement, $test, $it->id]) }}" class="mt-1 space-y-1">
                                    @csrf
                                    <div class="flex gap-3"><label><input type="radio" name="result" value="no_exception" @checked($it->result === 'no_exception') required> No exception</label><label><input type="radio" name="result" value="exception" @checked($it->result === 'exception')> Exception</label></div>
                                    <input name="note" value="{{ $it->note }}" class="input !py-1 text-xs" placeholder="Support inspected / explanation (required for an exception)">
                                    <select name="evidence" class="input !py-1 text-xs"><option value="">Evidence document (optional)</option>@foreach ($documents as $d)<option value="{{ $d->uuid }}" @selected($it->evidence?->uuid === $d->uuid)>{{ $d->title }}</option>@endforeach</select>
                                    <button class="btn-secondary btn-sm">Save</button>
                                </form>
                            </details>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-6 max-w-2xl">
        @if ($test->status === 'concluded')
            <div class="card card-body text-sm"><div class="font-semibold text-navy-900">Conclusion</div><p class="mt-1 whitespace-pre-line text-ink-700">{{ $test->conclusion }}</p>
                <p class="mt-2 text-xs text-ink-500">Concluded by {{ $test->concluder?->name }} on {{ local_time($test->concluded_at, 'j M Y') }}</p></div>
        @elseif ($canConclude)
            <form method="POST" action="{{ route('ledger.jet.conclude', [$engagement, $test]) }}" class="card card-body space-y-3">
                @csrf
                <label class="label">Conclusion</label>
                <textarea name="conclusion" rows="4" class="input" required placeholder="What the testing showed about the risk of management override, and any exceptions raised as findings."></textarea>
                <button class="btn-primary self-start">Conclude test</button>
            </form>
        @endif
    </div>
</x-layouts.app>
