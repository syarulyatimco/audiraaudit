<x-layouts.app :title="'Materiality · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-planning-nav :engagement="$engagement" />

    @php
        $benchmarkOptions = \App\Enums\MaterialityBenchmark::options();
        $form = $open && $open->isEditable() ? $open : null;
        $showForm = $canEditPlanning && ! $pendingApproval && ($form || (! $current && ! $open));
        $seed = $form ?? $current;
    @endphp

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <p class="rounded-md border border-navy-100 bg-white px-4 py-3 text-xs text-ink-600">
                Benchmarks are taken from AUDIRA's adjusted trial balance. Percentages are your firm's methodology and the engagement team's professional judgement — no standard prescribes a fixed percentage. Overall, performance and clearly trivial amounts are calculated by AUDIRA, and materiality takes effect only when the engagement partner approves it.
            </p>

            @include('ai.partials.panel', ['agent' => 'materiality', 'task' => 'recommend_materiality', 'title' => 'AI materiality recommendation',
                'blurb' => 'The Materiality agent proposes a benchmark and percentages with rationale and cited library paragraphs. You decide; it only fills a draft if you choose to use it.',
                'show' => $canEditPlanning && ! $pendingApproval])

            {{-- Materiality in force --}}
            <div class="card">
                <div class="card-header">
                    <div><h2 class="card-title">Materiality in force</h2>
                        <p class="text-xs text-ink-500">{{ $current ? $current->label().' · approved '.local_time($current->approved_at, 'j M Y') : 'No approved materiality yet.' }}</p></div>
                    @if ($current)<x-status :value="$current->status" />@endif
                </div>
                @if ($current)
                    @if ($drift)
                        <p class="mx-5 mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">The {{ strtolower($current->benchmark->label()) }} in the trial balance is now RM {{ amount(abs($benchmarks['current'][$current->benchmark->value])) }}, not the RM {{ amount($current->benchmark_amount_sen) }} used. Consider whether materiality needs to be revised.</p>
                    @endif
                    <div class="grid gap-4 px-5 py-4 sm:grid-cols-3">
                        @foreach ([['Overall materiality', $current->om_sen, $current->om_percentage.'% of '.strtolower($current->benchmark->label())], ['Performance materiality', $current->pm_sen, $current->pm_percentage.'% of overall'], ['Clearly trivial', $current->ct_sen, $current->ct_percentage.'% of overall']] as [$l, $v, $sub])
                            <div class="rounded-md border border-navy-50 px-4 py-3">
                                <div class="text-2xs font-semibold uppercase tracking-wider text-ink-500">{{ $l }}</div>
                                <div class="mt-1 text-xl font-semibold tabular-nums text-navy-900">RM {{ amount($v) }}</div>
                                <div class="text-xs text-ink-500">{{ $sub }}</div>
                            </div>
                        @endforeach
                    </div>
                    <dl class="px-5 pb-2">
                        <div class="dl-row"><dt>Benchmark</dt><dd>{{ $current->benchmark->label() }} · RM {{ amount($current->benchmark_amount_sen) }} ({{ $current->benchmark_basis->label() }})</dd></div>
                        @if ($current->benchmark_reason)<div class="dl-row"><dt>Benchmark note</dt><dd class="max-w-md whitespace-pre-line text-left font-normal">{{ $current->benchmark_reason }}</dd></div>@endif
                    </dl>
                    <div class="space-y-3 px-5 pb-4 text-sm">
                        <div><div class="label">Rationale</div><p class="whitespace-pre-line text-ink-700">{{ $current->rationale }}</p></div>
                        @if ($current->pm_rationale)<div><div class="label">Performance materiality</div><p class="whitespace-pre-line text-ink-700">{{ $current->pm_rationale }}</p></div>@endif
                        @if ($current->ct_rationale)<div><div class="label">Clearly trivial</div><p class="whitespace-pre-line text-ink-700">{{ $current->ct_rationale }}</p></div>@endif
                    </div>
                    @if ($current->specifics->isNotEmpty())
                        <table class="table border-t border-navy-50">
                            <thead><tr><th>Specific materiality</th><th>FSLI</th><th class="text-right">Amount (RM)</th><th>Rationale</th></tr></thead>
                            <tbody>@foreach ($current->specifics as $s)<tr><td class="font-medium">{{ $s->area }}</td><td>{{ $s->fsli?->name ?? '—' }}</td><td class="text-right tabular-nums">{{ amount($s->amount_sen) }}</td><td class="text-xs">{{ $s->rationale }}</td></tr>@endforeach</tbody>
                        </table>
                    @endif
                    @if ($canEditPlanning && ! $open)
                        <form method="POST" action="{{ route('materiality.revise', $engagement) }}" class="flex flex-wrap items-end gap-3 border-t border-navy-100/70 px-5 py-4">
                            @csrf
                            <x-select name="stage" label="Revise for" :options="['revised' => 'Revision during the audit', 'final' => 'Final materiality (completion)']" class="w-56" />
                            <x-field name="revision_reason" label="Why revise?" class="min-w-[16rem] flex-1" required />
                            <button class="btn-secondary">Start revision</button>
                        </form>
                    @endif
                @endif
            </div>

            {{-- Version awaiting a decision --}}
            @if ($open && $pendingApproval)
                <div class="card">
                    <div class="card-header"><h2 class="card-title">{{ $open->label() }}</h2><x-status :value="$open->status" /></div>
                    <div class="card-body space-y-4">
                        <div class="grid gap-3 text-sm sm:grid-cols-3">
                            <div><div class="label">Overall</div><div class="tabular-nums">RM {{ amount($open->om_sen) }} <span class="text-xs text-ink-500">({{ $open->om_percentage }}% of {{ strtolower($open->benchmark->label()) }} RM {{ amount($open->benchmark_amount_sen) }})</span></div></div>
                            <div><div class="label">Performance</div><div class="tabular-nums">RM {{ amount($open->pm_sen) }} <span class="text-xs text-ink-500">({{ $open->pm_percentage }}%)</span></div></div>
                            <div><div class="label">Clearly trivial</div><div class="tabular-nums">RM {{ amount($open->ct_sen) }} <span class="text-xs text-ink-500">({{ $open->ct_percentage }}%)</span></div></div>
                        </div>
                        <div class="text-sm"><div class="label">Rationale</div><p class="whitespace-pre-line text-ink-700">{{ $open->rationale }}</p></div>
                        @if ($open->revision_reason)<div class="text-sm"><div class="label">Reason for revision</div><p class="text-ink-700">{{ $open->revision_reason }}</p></div>@endif
                        @foreach ($open->specifics as $s)<p class="text-sm"><span class="font-medium">{{ $s->area }}</span> — RM {{ amount($s->amount_sen) }}: <span class="text-ink-600">{{ $s->rationale }}</span></p>@endforeach
                        @include('planning.partials.approval', ['approveLabel' => 'Approve materiality'])
                    </div>
                </div>
            @endif

            {{-- Draft form --}}
            @if ($showForm)
                @php
                    $rejection = $form?->approvals()->where('status', 'rejected')->first();
                    $rows = old('specifics', $form ? $form->specifics->map(fn ($s) => ['area' => $s->area, 'engagement_fsli_id' => $s->engagement_fsli_id, 'amount' => number_format($s->amount_sen / 100, 2, '.', ''), 'rationale' => $s->rationale])->all() : []);
                    $init = [
                        'benchmark' => old('benchmark', $seed?->benchmark?->value ?? 'profit_before_tax'),
                        'basis' => old('benchmark_basis', $seed?->benchmark_basis?->value ?? 'current_year'),
                        'manual' => old('benchmark_amount', $seed && ! in_array($seed->benchmark_basis->value, ['current_year', 'prior_year']) ? number_format($seed->benchmark_amount_sen / 100, 2, '.', '') : ''),
                        'om' => old('om_percentage', $seed?->om_percentage ?? ''), 'pm' => old('pm_percentage', $seed?->pm_percentage ?? ''), 'ct' => old('ct_percentage', $seed?->ct_percentage ?? ''),
                        'cy' => $benchmarks['current'], 'py' => $benchmarks['prior'], 'rows' => array_values($rows),
                    ];
                @endphp
                <form method="POST" action="{{ route('materiality.save', $engagement) }}" class="card" x-data="materialityForm(@js($init))">
                    @csrf @method('PUT')
                    <div class="card-header">
                        <div><h2 class="card-title">{{ $form ? $form->label() : ($current ? 'New version' : 'Planning materiality') }}</h2>
                            <p class="text-xs text-ink-500">{{ $form?->source === 'ai' ? 'Started from an AI recommendation — review every figure and rationale.' : 'Draft — not in force until approved.' }}</p></div>
                        @if ($form)<x-status :value="$form->status" />@endif
                    </div>
                    <div class="card-body space-y-5">
                        @if ($rejection)<p class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"><span class="font-semibold">Returned by {{ $rejection->decider?->name }}:</span> {{ $rejection->decision_comment }}</p>@endif
                        @foreach ($benchmarks['issues'] as $issue)<p class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">{{ $issue }}</p>@endforeach

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="label" for="benchmark">Benchmark<span class="text-red-500"> *</span></label>
                                <select id="benchmark" name="benchmark" class="input" x-model="benchmark">@foreach ($benchmarkOptions as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                                @error('benchmark')<p class="error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="label" for="benchmark_basis">Basis<span class="text-red-500"> *</span></label>
                                <select id="benchmark_basis" name="benchmark_basis" class="input" x-model="basis">@foreach (\App\Enums\BenchmarkBasis::options() as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                                @error('benchmark_basis')<p class="error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="label">Benchmark amount (RM)</label>
                                <template x-if="fromTb()"><div><div class="input bg-navy-50/50 tabular-nums" x-text="tbAmount() === null ? 'Not available' : money(Math.abs(tbAmount()))"></div><p class="hint">Taken from the adjusted trial balance.</p></div></template>
                                <template x-if="!fromTb()"><input name="benchmark_amount" class="input tabular-nums" x-model="manual" placeholder="0.00"></template>
                                @error('benchmark_amount')<p class="error">{{ $message }}</p>@enderror
                            </div>
                            <x-field name="benchmark_reason" label="Benchmark note" :value="$seed?->benchmark_reason" hint="Required for a manual amount or a negative benchmark (e.g. a loss)." />
                        </div>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <div><label class="label" for="om_percentage">Overall % of benchmark *</label><input id="om_percentage" name="om_percentage" class="input" x-model="om" inputmode="decimal">@error('om_percentage')<p class="error">{{ $message }}</p>@enderror</div>
                            <div><label class="label" for="pm_percentage">Performance % of overall *</label><input id="pm_percentage" name="pm_percentage" class="input" x-model="pm" inputmode="decimal">@error('pm_percentage')<p class="error">{{ $message }}</p>@enderror</div>
                            <div><label class="label" for="ct_percentage">Clearly trivial % of overall *</label><input id="ct_percentage" name="ct_percentage" class="input" x-model="ct" inputmode="decimal">@error('ct_percentage')<p class="error">{{ $message }}</p>@enderror</div>
                        </div>
                        <div class="grid gap-3 rounded-md bg-navy-50/60 px-4 py-3 text-sm sm:grid-cols-3">
                            <div><span class="text-xs text-ink-500">Overall</span><div class="font-semibold tabular-nums text-navy-900" x-text="'RM ' + money(omAmt())"></div></div>
                            <div><span class="text-xs text-ink-500">Performance</span><div class="font-semibold tabular-nums text-navy-900" x-text="'RM ' + money(pmAmt())"></div></div>
                            <div><span class="text-xs text-ink-500">Clearly trivial</span><div class="font-semibold tabular-nums text-navy-900" x-text="'RM ' + money(ctAmt())"></div></div>
                        </div>

                        <x-field name="rationale" label="Rationale for benchmark and percentage" type="textarea" rows="4" :value="$seed?->rationale" required hint="Why this benchmark reflects what users of the financial statements focus on, and how the percentage was judged. Cite library paragraphs on working paper D1." />
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-field name="pm_rationale" label="Performance materiality rationale" type="textarea" rows="3" :value="$seed?->pm_rationale" hint="e.g. expected misstatements, prior-year findings, control environment." />
                            <x-field name="ct_rationale" label="Clearly trivial rationale" type="textarea" rows="3" :value="$seed?->ct_rationale" />
                        </div>
                        @if ($form ? $form->version > 1 : (bool) $current)
                            <x-field name="revision_reason" label="Reason for revision" :value="$form?->revision_reason" required />
                        @endif

                        <div>
                            <div class="flex items-center justify-between"><div class="label mb-0">Specific materiality</div><button type="button" class="btn-ghost btn-sm" @click="rows.push({area: '', engagement_fsli_id: '', amount: '', rationale: ''})">+ Add</button></div>
                            <p class="hint">Lower materiality for particular balances, classes of transactions or disclosures (for example related-party transactions or directors' remuneration).</p>
                            <template x-for="(row, i) in rows" :key="i">
                                <div class="mt-3 grid gap-2 rounded-md border border-navy-50 p-3 sm:grid-cols-12">
                                    <input class="input sm:col-span-4" :name="`specifics[${i}][area]`" x-model="row.area" placeholder="Area" required>
                                    <select class="input sm:col-span-3" :name="`specifics[${i}][engagement_fsli_id]`" x-model="row.engagement_fsli_id"><option value="">No FSLI</option>@foreach ($fslis as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach</select>
                                    <input class="input tabular-nums sm:col-span-2" :name="`specifics[${i}][amount]`" x-model="row.amount" placeholder="RM" required>
                                    <button type="button" class="btn-ghost btn-sm sm:col-span-3" @click="rows.splice(i, 1)">Remove</button>
                                    <input class="input sm:col-span-12" :name="`specifics[${i}][rationale]`" x-model="row.rationale" placeholder="Rationale" required>
                                </div>
                            </template>
                            @error('specifics.*')<p class="error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-2 border-t border-navy-100/70 px-5 py-3">
                        <button class="btn-primary">Save draft</button>
                    </div>
                </form>
                @if ($form)
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <form method="POST" action="{{ route('materiality.discard', [$engagement, $form]) }}" onsubmit="return confirm('Discard this draft?')">@csrf @method('DELETE')<button class="btn-ghost btn-sm text-red-700">Discard draft</button></form>
                        <form method="POST" action="{{ route('materiality.submit', [$engagement, $form]) }}" class="flex flex-1 flex-wrap items-end justify-end gap-2">
                            @csrf
                            <x-field name="comment" label="Note to the engagement partner" class="min-w-[16rem] flex-1" />
                            <button class="btn-accent">Submit saved draft for approval</button>
                        </form>
                    </div>
                @endif
            @elseif (! $canEditPlanning && ! $current)
                <div class="card card-body text-sm text-ink-500">Materiality has not been set yet.</div>
            @endif

            {{-- History --}}
            @if ($history->isNotEmpty())
                <div class="card overflow-x-auto">
                    <div class="card-header"><h2 class="card-title">Version history</h2></div>
                    <table class="table">
                        <thead><tr><th>Version</th><th>Benchmark</th><th class="text-right">Overall (RM)</th><th class="text-right">Performance (RM)</th><th>Status</th><th>Decision</th></tr></thead>
                        <tbody>
                        @foreach ($history as $h)
                            @php $d = $h->approvals->first(); @endphp
                            <tr>
                                <td><span class="font-medium">v{{ $h->version }}</span> <span class="text-xs text-ink-500">{{ $h->stage->label() }}</span>@if($h->source === 'ai')<span class="badge badge-teal ml-1">AI draft</span>@endif</td>
                                <td class="text-xs">{{ $h->benchmark->label() }} · {{ $h->om_percentage }}%</td>
                                <td class="text-right tabular-nums">{{ amount($h->om_sen) }}</td>
                                <td class="text-right tabular-nums">{{ amount($h->pm_sen) }}</td>
                                <td><x-status :value="$h->status" /></td>
                                <td class="text-xs text-ink-500">{{ $d && $d->decided_at ? $d->decider?->name.' · '.local_time($d->decided_at, 'j M Y') : '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Benchmark figures</h2><span class="text-xs text-ink-500">Adjusted TB</span></div>
                <dl class="px-5 py-2">
                    @foreach ($benchmarkOptions as $k => $l)
                        @continue($k === 'other')
                        <div class="dl-row"><dt>{{ $l }}</dt><dd class="tabular-nums">{{ isset($benchmarks['current'][$k]) ? amount($benchmarks['current'][$k]) : '—' }}@if(isset($benchmarks['prior'][$k]))<div class="text-2xs font-normal text-ink-400">PY {{ amount($benchmarks['prior'][$k]) }}</div>@endif</dd></div>
                    @endforeach
                </dl>
            </div>
            @include('planning.partials.paper')
        </div>
    </div>

    <script>
        function materialityForm(init) {
            return {
                ...init,
                fromTb() { return ['current_year', 'prior_year'].includes(this.basis) && this.benchmark !== 'other'; },
                tbAmount() { const src = this.basis === 'prior_year' ? this.py : this.cy; return src && src[this.benchmark] !== undefined ? src[this.benchmark] : null; },
                base() { if (this.fromTb()) { const a = this.tbAmount(); return a === null ? 0 : Math.abs(a); } return Math.round(parseFloat(String(this.manual).replace(/,/g, '')) * 100) || 0; },
                omAmt() { return Math.round(this.base() * (parseFloat(this.om) || 0) / 100); },
                pmAmt() { return Math.round(this.omAmt() * (parseFloat(this.pm) || 0) / 100); },
                ctAmt() { return Math.round(this.omAmt() * (parseFloat(this.ct) || 0) / 100); },
                money(sen) { return (sen / 100).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
            };
        }
    </script>
</x-layouts.app>
