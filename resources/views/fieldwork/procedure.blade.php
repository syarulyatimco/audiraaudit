<x-layouts.app :title="$procedure->reference.' · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-fieldwork-nav :engagement="$engagement" />
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card">
                <div class="card-header">
                    <div><a href="{{ route('programme.index', $engagement) }}" class="text-xs text-ink-500 hover:text-navy-900">← Audit programme</a>
                        <h2 class="mt-1 text-lg font-semibold">{{ $procedure->reference }} · {{ $procedure->title }}</h2>
                        <p class="text-xs text-ink-500">{{ $procedure->nature->label() }} · {{ $procedure->timing->label() }}{{ $procedure->fsli ? ' · '.$procedure->fsli->name : '' }}@if($procedure->source === 'ai') · AI draft @endif</p></div>
                    <div class="flex items-center gap-2"><x-status :value="$procedure->status" />@if ($canManage && $procedure->isActive())<a href="{{ route('programme.edit', [$engagement, $procedure]) }}" class="btn-secondary btn-sm">Edit</a>@endif</div>
                </div>
                <div class="card-body space-y-4 text-sm">
                    <div><div class="label">Steps</div><p class="whitespace-pre-line text-ink-700">{{ $procedure->steps }}</p></div>
                    <dl>
                        <div class="dl-row"><dt>Responds to</dt><dd>@forelse ($procedure->risks as $r)<a class="text-navy-900 hover:text-teal-700" href="{{ route('risks.show', [$engagement, $r]) }}">{{ $r->reference }} {{ $r->title }}</a>@if(! $loop->last), @endif @empty — @endforelse</dd></div>
                        <div class="dl-row"><dt>Assertions</dt><dd>{{ collect($procedure->assertions)->map(fn ($a) => \App\Enums\Assertion::from($a)->label())->join(', ') ?: '—' }}</dd></div>
                        <div class="dl-row"><dt>Assigned to</dt><dd>{{ $procedure->assignee?->name ?? '—' }}{{ $procedure->budget_hours ? ' · '.$procedure->budget_hours.' h' : '' }}</dd></div>
                    </dl>
                    @if ($procedure->withdrawn_at)<p class="rounded-md bg-navy-50 px-3 py-2 text-xs text-ink-600">Withdrawn: {{ $procedure->withdrawn_reason }}</p>@endif
                </div>
            </div>

            @if ($canExecute && $procedure->isActive())
                <form method="POST" action="{{ route('programme.progress', [$engagement, $procedure]) }}" class="card">
                    @csrf
                    <div class="card-header"><h2 class="card-title">Execution</h2></div>
                    <div class="card-body space-y-4">
                        <x-select name="status" label="Status" :options="\App\Enums\ProcedureStatus::options()" :value="$procedure->status" class="max-w-xs" />
                        <x-field name="conclusion" label="Conclusion" type="textarea" rows="3" :value="$procedure->conclusion" hint="Required to mark the procedure completed." />
                    </div>
                    <div class="flex justify-end border-t border-navy-100/70 px-5 py-3"><button class="btn-primary">Save</button></div>
                </form>
            @elseif ($procedure->conclusion)
                <div class="card card-body text-sm"><div class="label">Conclusion</div><p class="whitespace-pre-line">{{ $procedure->conclusion }}</p></div>
            @endif
        </div>
        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Working paper and evidence</h2></div>
                <div class="card-body text-sm">
                    @if ($procedure->workingPaper)
                        <a href="{{ route('working-papers.show', [$engagement, $procedure->workingPaper]) }}" class="flex items-center justify-between text-navy-900 hover:text-teal-700"><span><span class="font-mono text-xs font-semibold">{{ $procedure->workingPaper->reference }}</span> {{ $procedure->workingPaper->title }}</span><x-status :value="$procedure->workingPaper->status" /></a>
                        <p class="mt-1 text-xs text-ink-500">{{ $procedure->workingPaper->documents()->count() }} evidence document(s) linked.</p>
                    @elseif ($canExecute && $procedure->isActive())
                        <form method="POST" action="{{ route('programme.paper', [$engagement, $procedure]) }}">@csrf
                            <p class="mb-2 text-xs text-ink-500">Document the work performed, link evidence and sign off in a fieldwork working paper.</p>
                            <button class="btn-secondary btn-sm">Open working paper</button></form>
                    @else
                        <p class="text-ink-500">No working paper yet.</p>
                    @endif
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Findings</h2>@if($canFindings && $procedure->isActive())<a href="{{ route('findings.create', [$engagement, 'procedure' => $procedure->uuid]) }}" class="text-xs text-teal-700">Record finding</a>@endif</div>
                <ul class="divide-y divide-navy-50 text-sm">
                    @forelse ($procedure->findings as $f)
                        <li class="flex items-center justify-between px-5 py-3"><a href="{{ route('findings.show', [$engagement, $f]) }}" class="text-navy-900 hover:text-teal-700"><span class="font-mono text-xs font-semibold">{{ $f->reference }}</span> {{ $f->title }}</a><x-status :value="$f->status" /></li>
                    @empty
                        <li class="px-5 py-3 text-ink-500">None.</li>
                    @endforelse
                </ul>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Sampling plans</h2>@if($canSample && $procedure->isActive())<a href="{{ route('sampling.index', [$engagement, 'procedure' => $procedure->id]) }}" class="text-xs text-teal-700">New plan</a>@endif</div>
                <ul class="divide-y divide-navy-50 text-sm">
                    @forelse ($procedure->samplingPlans as $s)
                        <li class="flex items-center justify-between px-5 py-3"><a href="{{ route('sampling.show', [$engagement, $s]) }}" class="text-navy-900 hover:text-teal-700">{{ $s->reference }} {{ $s->title }}</a><x-status :value="$s->status" /></li>
                    @empty
                        <li class="px-5 py-3 text-ink-500">None.</li>
                    @endforelse
                </ul>
            </div>
            @if ($canManage && $procedure->isActive() && $procedure->samplingPlans->isEmpty())
                <form method="POST" action="{{ route('programme.withdraw', [$engagement, $procedure]) }}" class="card card-body space-y-2">@csrf
                    <x-field name="reason" label="Withdraw — reason" required /><button class="btn-danger btn-sm">Withdraw procedure</button></form>
            @endif
        </div>
    </div>
</x-layouts.app>
