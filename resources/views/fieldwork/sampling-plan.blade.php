<x-layouts.app :title="$plan->reference.' · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-fieldwork-nav :engagement="$engagement" />
    @php
        $st = $plan->status->value;
        $editable = $canManage && $plan->isEditable();
        $monetary = $plan->test_type === \App\Enums\SampleTestType::Monetary;
        $diff = $plan->reconciliationDifference();
        $rm = fn ($sen) => $sen === null ? '' : number_format($sen / 100, 2, '.', '');
        $calc = $plan->size_calculation;
    @endphp
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card">
                <div class="card-header">
                    <div><a href="{{ route('sampling.index', $engagement) }}" class="text-xs text-ink-500 hover:text-navy-900">← Sampling</a>
                        <h2 class="mt-1 text-lg font-semibold">{{ $plan->reference }} · {{ $plan->title }}</h2>
                        <p class="text-xs text-ink-500">{{ $plan->test_type->label() }} · {{ $plan->method->label() }} · <span class="font-semibold">{{ $plan->approach->label() }}</span>@if($plan->procedure) · <a class="text-teal-700" href="{{ route('programme.show', [$engagement, $plan->procedure]) }}">{{ $plan->procedure->reference }}</a>@endif</p></div>
                    <x-status :value="$plan->status" />
                </div>
                <div class="card-body text-sm"><p class="text-ink-700">{{ $plan->objective }}</p></div>
            </div>

            {{-- 1. Population --}}
            <div class="card">
                <div class="card-header"><h2 class="card-title">1 · Population</h2>@if($plan->population_sha256)<span class="font-mono text-2xs text-ink-400" title="SHA-256 of the population">{{ substr($plan->population_sha256, 0, 16) }}…</span>@endif</div>
                <div class="card-body space-y-3 text-sm">
                    @if ($plan->population_count)
                        <p>{{ number_format($plan->population_count) }} items totalling <span class="font-semibold tabular-nums">RM {{ amount($plan->population_value_sen) }}</span> from {{ $plan->population_source }}.</p>
                        @if ($plan->control_total_sen !== null)
                            <p class="{{ $diff === 0 ? 'text-emerald-700' : 'text-amber-800' }}">Control total RM {{ amount($plan->control_total_sen) }} ({{ $plan->control_total_source }}) — {{ $diff === 0 ? 'reconciled.' : 'difference RM '.amount($diff).'. '.$plan->reconciliation_note }}</p>
                        @endif
                    @else
                        <p class="text-ink-500">No population loaded.</p>
                    @endif
                    @if ($editable)
                        <form method="POST" action="{{ route('sampling.population', [$engagement, $plan]) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2">
                            @csrf
                            <div class="flex-1"><label class="label" for="file">{{ $plan->population_count ? 'Replace population' : 'Load population' }} (CSV/XLSX with reference and amount columns)</label><input id="file" type="file" name="file" class="input" required>@error('file')<p class="error">{{ $message }}</p>@enderror</div>
                            <button class="btn-secondary">Upload</button>
                        </form>
                    @endif
                </div>
            </div>

            {{-- 2. Parameters and size --}}
            <div class="card">
                <div class="card-header"><h2 class="card-title">2 · Completeness, parameters and sample size</h2></div>
                @if ($editable && $plan->population_count)
                    <form method="POST" action="{{ route('sampling.parameters', [$engagement, $plan]) }}" class="card-body space-y-4" x-data>
                        @csrf @method('PUT')
                        @error('parameters')<p class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-field name="control_total" label="Control total (RM)" :value="$rm($plan->control_total_sen)" :hint="$plan->engagement_fsli_id ? 'Leave blank to use the FSLI adjusted balance.' : 'Ledger or sub-ledger total the population must agree to.'" />
                            <x-field name="control_total_source" label="Control total source" :value="$plan->control_total_source" />
                        </div>
                        <x-field name="reconciliation_note" label="Reconciliation note (required if the totals differ)" :value="$plan->reconciliation_note" />
                        @if ($monetary)
                            <div class="grid gap-4 sm:grid-cols-3">
                                <x-field name="tolerable_misstatement" label="Tolerable misstatement (RM)" :value="$rm($plan->tolerable_misstatement_sen)" :hint="$materiality ? 'Max RM '.amount($materiality->pm_sen).' (performance materiality)' : 'Approve materiality first'" />
                                <x-field name="expected_misstatement" label="Expected misstatement (RM)" :value="$rm($plan->expected_misstatement_sen)" />
                                <x-field name="key_item_threshold" label="Key item threshold (RM)" :value="$rm($plan->key_item_threshold_sen)" hint="Items at or above are tested in full." />
                            </div>
                        @else
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-field name="tolerable_deviation_rate" label="Tolerable deviation rate (%)" :value="$plan->tolerable_deviation_rate" />
                                <x-field name="expected_deviation_rate" label="Expected deviation rate (%)" :value="$plan->expected_deviation_rate" />
                            </div>
                        @endif
                        <div class="grid gap-4 sm:grid-cols-3">
                            @if ($plan->approach === \App\Enums\SamplingApproach::Statistical || ! $monetary)
                                <x-field name="confidence_level" label="Confidence level (%)" :value="$plan->confidence_level" />
                            @endif
                            @if ($monetary && $plan->approach === \App\Enums\SamplingApproach::Statistical)
                                <x-field name="expansion_factor" label="Expansion factor (firm methodology)" :value="$plan->expansion_factor" hint="Needed only when misstatement is expected." />
                            @endif
                            @if ($monetary && $plan->approach === \App\Enums\SamplingApproach::NonStatistical && ! $plan->method->isManual())
                                <x-field name="assurance_factor" label="Assurance factor (firm methodology)" :value="$plan->assurance_factor" />
                            @endif
                        </div>
                        @if ($plan->method === \App\Enums\SamplingMethod::Stratified)
                            <div><span class="label">Stratum upper bounds (RM, ascending; the last stratum is open-ended)</span>
                                <div class="grid gap-2 sm:grid-cols-4">@for ($i = 0; $i < 4; $i++)<input name="strata[]" class="input" value="{{ isset($plan->strata[$i]) ? $rm($plan->strata[$i]) : '' }}">@endfor</div></div>
                        @endif
                        <x-field name="method_rationale" label="Method and methodology rationale" type="textarea" rows="3" :value="$plan->method_rationale" hint="Why this method and these parameters; for non-statistical samples, the firm methodology the factor comes from; for judgemental selection, how items will be chosen." />
                        <div class="flex justify-end"><button class="btn-primary">Save and calculate</button></div>
                    </form>
                @endif
                <div class="card-body border-t border-navy-50 text-sm">
                    @if ($calc)
                        <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                            <div><span class="text-xs text-ink-500">Sample size</span><div class="text-2xl font-semibold tabular-nums text-navy-900">{{ $plan->sample_size }}</div></div>
                            <div class="text-xs text-ink-600"><div class="font-mono">{{ $calc['formula'] }}</div>
                                <div class="mt-1">@foreach ($calc['inputs'] as $k => $v)@if($v !== null)<span class="mr-3">{{ $k }} = {{ is_int($v) && str_ends_with($k, '_sen') ? 'RM '.amount($v) : $v }}</span>@endif @endforeach</div>
                                @if (isset($calc['interval_sen']))<div class="mt-1">Sampling interval RM {{ amount($calc['interval_sen']) }}</div>@endif
                                @if (! empty($calc['capped_to_population']))<div class="mt-1 text-amber-700">Capped at the population size.</div>@endif</div>
                        </div>
                        <p class="mt-2 text-xs text-ink-500">Key items at or above the threshold are selected in addition to this size.</p>
                    @elseif ($plan->method->isManual())
                        <p class="text-ink-600">{{ $plan->method->label() }}: the auditor chooses the items after approval, with a reason for each. This sample is non-statistical; results are projected by ratio as an indication only.</p>
                    @else
                        <p class="text-ink-500">Save the parameters to calculate the sample size.</p>
                    @endif
                    @if ($plan->method_rationale && ! $editable)<p class="mt-3 whitespace-pre-line text-ink-700">{{ $plan->method_rationale }}</p>@endif
                </div>
            </div>

            @if ($editable && $plan->population_count)
                @include('ai.partials.panel', ['agent' => 'sampling', 'task' => 'recommend_parameters', 'subject' => $plan->uuid, 'title' => 'AI parameter recommendation',
                    'blurb' => 'The Sampling agent reviews the population and recommends parameters with rationale. It never sets the sample size.', 'show' => true])
            @endif

            {{-- 3. Approval --}}
            <div class="card">
                <div class="card-header"><h2 class="card-title">3 · Approval</h2></div>
                <div class="card-body space-y-3">
                    @if ($pendingApproval)
                        @include('planning.partials.approval', ['approveLabel' => 'Approve sampling plan'])
                    @elseif ($editable && $plan->control_total_sen !== null && ($plan->sample_size || $plan->method->isManual()))
                        @php $rej = $plan->approvals->firstWhere('status', \App\Enums\ApprovalStatus::Rejected); @endphp
                        @if ($st === 'rejected' && $rej)<p class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">Returned by {{ $rej->decider?->name }}: {{ $rej->decision_comment }}</p>@endif
                        <form method="POST" action="{{ route('sampling.submit', [$engagement, $plan]) }}" class="flex flex-wrap items-end gap-2">@csrf
                            <x-field name="comment" label="Note to the approver" class="min-w-[16rem] flex-1" /><button class="btn-accent">Submit for approval</button></form>
                    @elseif (in_array($st, ['approved', 'selected', 'evaluated']))
                        @php $ok = $plan->approvals->firstWhere('status', \App\Enums\ApprovalStatus::Approved); @endphp
                        <p class="text-sm text-emerald-800">Approved by {{ $ok?->decider?->name }} {{ $ok?->decided_at ? local_time($ok->decided_at) : '' }}. The population and parameters are now frozen.</p>
                    @else
                        <p class="text-sm text-ink-500">Complete the population and parameters, then submit. The engagement partner or manager approves; selection is possible only after approval.</p>
                    @endif
                </div>
            </div>

            {{-- 4. Selection --}}
            <div class="card">
                <div class="card-header"><h2 class="card-title">4 · Selection</h2>@if($plan->selected_at)<span class="text-xs text-ink-500">{{ local_time($plan->selected_at) }}</span>@endif</div>
                <div class="card-body text-sm">
                    @if ($st === 'approved' && $canManage)
                        @if ($plan->method->isManual())
                            <form method="POST" action="{{ route('sampling.select', [$engagement, $plan]) }}">@csrf
                                <p class="mb-2 text-ink-600">Give a reason against each item you select (largest items shown first).</p>
                                <div class="max-h-96 overflow-y-auto rounded-md border border-navy-100">
                                    <table class="table text-xs"><thead><tr><th>Ref</th><th>Description</th><th class="text-right">Amount</th><th>Reason for selection</th></tr></thead><tbody>
                                    @foreach ($population as $pi)<tr><td class="font-mono">{{ $pi->item_ref }}</td><td>{{ $pi->description }}</td><td class="text-right tabular-nums">{{ amount($pi->amount_sen) }}</td><td><input name="items[{{ $pi->id }}]" class="input py-1 text-xs" placeholder="Leave blank to skip"></td></tr>@endforeach
                                    </tbody></table>
                                </div>
                                @error('items')<p class="error">{{ $message }}</p>@enderror
                                <div class="mt-3 flex justify-end"><button class="btn-accent">Record selection</button></div></form>
                        @else
                            <form method="POST" action="{{ route('sampling.select', [$engagement, $plan]) }}" class="flex items-center justify-between gap-3">@csrf
                                <p class="text-ink-600">AUDIRA generates a random seed, records it with the algorithm and population hash, and selects the items. Anyone can re-perform the selection.</p>
                                <button class="btn-accent shrink-0">Select sample</button></form>
                        @endif
                    @elseif ($plan->selected_at)
                        <dl>
                            <div class="dl-row"><dt>Algorithm</dt><dd class="font-mono text-xs">{{ $plan->selection_algorithm }}</dd></div>
                            @if ($plan->selection_seed)<div class="dl-row"><dt>Seed</dt><dd class="font-mono text-xs">{{ $plan->selection_seed }}</dd></div>@endif
                            <div class="dl-row"><dt>Population SHA-256</dt><dd class="break-all font-mono text-2xs">{{ $plan->population_sha256 }}</dd></div>
                            @if ($reproducible !== null)<div class="dl-row"><dt>Re-performed now</dt><dd class="{{ $reproducible ? 'text-emerald-700' : 'text-red-700' }}">{{ $reproducible ? 'Same items selected' : 'Mismatch — investigate' }}</dd></div>@endif
                        </dl>
                    @else
                        <p class="text-ink-500">Available after approval.</p>
                    @endif
                </div>
            </div>

            {{-- 5. Results --}}
            @if ($st === 'selected' && $items->whereNull('result')->isNotEmpty())
                @include('ai.partials.panel', ['agent' => 'testing_evidence', 'task' => 'draft_test_results', 'subject' => $plan->uuid, 'aiRun' => $testRun, 'aiUnavailable' => $testUnavailable,
                    'title' => 'AI test drafting', 'blurb' => 'The Testing & Evidence agent compares untested items with engagement documents that mention them and drafts results quoting the evidence. You review and record each one.', 'show' => $canExecute])
            @endif
            @if ($items->isNotEmpty())
                <div class="card overflow-x-auto">
                    <div class="card-header"><h2 class="card-title">5 · Results</h2><span class="text-xs text-ink-500">{{ $items->whereNotNull('result')->count() }} / {{ $items->count() }} tested</span></div>
                    <table class="table text-xs">
                        <thead><tr><th>#</th><th>Item</th><th class="text-right">Book (RM)</th><th>Selected by</th><th>Result</th></tr></thead>
                        <tbody>
                        @foreach ($items as $it)
                            <tr>
                                <td>{{ $it->selection_order }}</td>
                                <td><span class="font-mono">{{ $it->populationItem->item_ref }}</span> <span class="text-ink-500">{{ $it->populationItem->description }}</span>@if($it->selection_note)<div class="text-ink-500">{{ $it->selection_note }}</div>@endif</td>
                                <td class="text-right tabular-nums">{{ amount($it->populationItem->amount_sen) }}</td>
                                <td>{{ str_replace('_', ' ', $it->selection_reason) }}{{ $it->stratum ? ' (stratum '.$it->stratum.')' : '' }}</td>
                                <td class="min-w-[16rem]">
                                    @if ($st === 'selected' && $canExecute)
                                        <form method="POST" action="{{ route('sampling.result', [$engagement, $plan, $it]) }}" class="flex flex-wrap items-center gap-1" x-data="{ r: @js($it->result ?? 'no_exception') }">@csrf
                                            <select name="result" class="input w-auto py-1 text-xs" x-model="r"><option value="no_exception">No exception</option><option value="exception">Exception</option></select>
                                            @if ($monetary)<input name="audited_amount" x-show="r === 'exception'" class="input w-28 py-1 text-xs" placeholder="Audited RM" value="{{ $it->result === 'exception' ? $rm($it->audited_amount_sen) : '' }}">@endif
                                            <input name="result_note" x-show="r === 'exception'" class="input w-40 py-1 text-xs" placeholder="Exception detail" value="{{ $it->result_note }}">
                                            <select name="evidence_document" class="input w-40 py-1 text-xs" title="Evidence document"><option value="">No evidence linked</option>@foreach ($documents as $d)<option value="{{ $d->uuid }}" @selected($it->evidence_document_id === $d->id)>{{ \Illuminate\Support\Str::limit($d->title, 40) }}</option>@endforeach</select>
                                            <button class="btn-secondary btn-sm">{{ $it->result ? 'Update' : 'Save' }}</button>
                                        </form>
                                    @else
                                        <x-status :value="$it->result ?? 'untested'" />@if($it->result === 'exception' && $monetary) <span class="tabular-nums">audited {{ amount($it->audited_amount_sen) }}</span>@endif
                                        @if ($it->result_note)<div class="text-ink-500">{{ $it->result_note }}</div>@endif
                                    @endif
                                    @if ($it->evidence)<div class="mt-1"><a href="{{ route('documents.show', [$engagement, $it->evidence]) }}" class="link">Evidence: {{ \Illuminate\Support\Str::limit($it->evidence->title, 40) }}</a></div>@endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- 6. Evaluation --}}
            @if (in_array($st, ['selected', 'evaluated']))
                @php $e = $plan->evaluation ?? $preview; @endphp
                <div class="card">
                    <div class="card-header"><h2 class="card-title">6 · Evaluation</h2>@if($st === 'selected')<span class="text-xs text-ink-500">Preview — updates as results are recorded</span>@endif</div>
                    <div class="card-body space-y-3 text-sm">
                        @if ($e)
                            <p class="text-xs text-ink-500">{{ $e['method'] }}</p>
                            <dl>
                                @foreach ($e as $k => $v)
                                    @continue(in_array($k, ['method', 'exceeds_tolerable', 'further_procedures_indicated']) || is_array($v))
                                    <div class="dl-row"><dt>{{ \Illuminate\Support\Str::headline(str_replace('_sen', '', $k)) }}</dt><dd class="tabular-nums">{{ is_int($v) && str_ends_with($k, '_sen') ? 'RM '.amount($v) : (is_float($v) && $v < 1 ? round($v * 100, 2).'%' : $v) }}</dd></div>
                                @endforeach
                            </dl>
                            @if ($e['exceeds_tolerable'])
                                <p class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-red-800">The result exceeds the tolerable amount. Consider further procedures, an adjustment or the effect on the audit opinion.</p>
                            @elseif ($e['further_procedures_indicated'])
                                <p class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900">Misstatement exceeds what was expected when the sample size was set. Consider whether the sample provides sufficient evidence or further procedures are needed.</p>
                            @else
                                <p class="text-emerald-700">Within tolerable limits and planning expectations.</p>
                            @endif
                        @endif
                        @if ($st === 'selected' && $canManage)
                            <form method="POST" action="{{ route('sampling.evaluate', [$engagement, $plan]) }}" class="space-y-2">@csrf
                                <x-field name="conclusion" label="Conclusion on the sample" type="textarea" rows="3" required /><div class="flex justify-end"><button class="btn-primary">Evaluate and conclude</button></div></form>
                        @elseif ($plan->conclusion)
                            <div><div class="label">Conclusion</div><p class="whitespace-pre-line text-ink-700">{{ $plan->conclusion }}</p></div>
                        @endif
                        @if ($st === 'evaluated' && $monetary)
                            <div class="border-t border-navy-50 pt-3">
                                <div class="label">Findings from this sample</div>
                                @forelse ($sampleFindings as $f)
                                    <a href="{{ route('findings.show', [$engagement, $f]) }}" class="flex justify-between py-1 text-sm hover:text-navy-900"><span><span class="font-mono text-xs font-semibold">{{ $f->reference }}</span> {{ $f->title }}</span><span class="tabular-nums">{{ amount($f->amount_sen) }}</span></a>
                                @empty
                                    @if ($canFindings)
                                        <form method="POST" action="{{ route('findings.from-sample', [$engagement, $plan]) }}" class="flex items-center justify-between gap-3">@csrf
                                            <p class="text-xs text-ink-500">Records a factual finding for the misstatements found and a projected finding for the rest of the projection, from the stored evaluation.</p>
                                            <button class="btn-secondary shrink-0">Record as findings</button></form>
                                    @else<p class="text-xs text-ink-500">None recorded.</p>@endif
                                @endforelse
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>
        <div class="space-y-6">
            @if ($population->isNotEmpty() && ! $plan->method->isManual())
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Largest items</h2></div>
                    <ul class="divide-y divide-navy-50 text-xs">@foreach ($population as $pi)<li class="flex justify-between px-5 py-2"><span class="font-mono">{{ $pi->item_ref }}</span><span class="tabular-nums">{{ amount($pi->amount_sen) }}</span></li>@endforeach</ul>
                </div>
            @endif
            <div class="card">
                <div class="card-header"><h2 class="card-title">Approval history</h2></div>
                <ul class="divide-y divide-navy-50 text-sm">
                    @forelse ($plan->approvals as $a)
                        <li class="px-5 py-3"><div class="flex justify-between"><span>{{ $a->requester->name }}</span><x-status :value="$a->status" /></div><div class="text-xs text-ink-500">{{ local_time($a->requested_at) }}@if($a->decided_at) · {{ $a->decider?->name }}@endif</div></li>
                    @empty<li class="px-5 py-3 text-ink-500">None.</li>@endforelse
                </ul>
            </div>
        </div>
    </div>
</x-layouts.app>
