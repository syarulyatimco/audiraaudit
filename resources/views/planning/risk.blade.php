<x-layouts.app :title="$risk->reference.' · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-planning-nav :engagement="$engagement" />
    @php $sig = $risk->significance_status; @endphp
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card">
                <div class="card-header">
                    <div><a href="{{ route('risks.index', $engagement) }}" class="text-xs text-ink-500 hover:text-navy-900">← Risk register</a>
                        <h2 class="mt-1 text-lg font-semibold">{{ $risk->reference }} · {{ $risk->title }}</h2>
                        <div class="mt-1 flex flex-wrap gap-1">
                            @if (! $risk->isActive())<span class="badge badge-neutral">Withdrawn</span>@endif
                            @if ($risk->is_fraud_risk)<span class="badge badge-danger">Fraud risk</span>@endif
                            @if ($risk->presumed_key)<span class="badge badge-neutral">Presumed</span>@endif
                            @if ($risk->source === 'ai')<span class="badge badge-teal">AI suggested</span>@endif
                        </div></div>
                    @if ($canManage && $risk->isActive() && $sig !== \App\Enums\SignificanceStatus::Pending)<a href="{{ route('risks.edit', [$engagement, $risk]) }}" class="btn-secondary btn-sm">Edit</a>@endif
                </div>
                <div class="card-body space-y-4 text-sm">
                    <p class="whitespace-pre-line text-ink-700">{{ $risk->description }}</p>
                    <dl>
                        <div class="dl-row"><dt>Level</dt><dd>{{ $risk->level->label() }}</dd></div>
                        @if ($risk->fslis->isNotEmpty())<div class="dl-row"><dt>FSLIs</dt><dd>{{ $risk->fslis->pluck('name')->join(', ') }}</dd></div>@endif
                        @if ($risk->assertions)<div class="dl-row"><dt>Assertions</dt><dd>{{ collect($risk->assertions)->map(fn ($a) => \App\Enums\Assertion::from($a)->label())->join(', ') }}</dd></div>@endif
                        <div class="dl-row"><dt>Inherent risk factors</dt><dd>{{ collect($risk->inherent_factors)->map(fn ($a) => \App\Enums\InherentRiskFactor::from($a)->label())->join(', ') ?: '—' }}</dd></div>
                        <div class="dl-row"><dt>Likelihood · magnitude</dt><dd>{{ $risk->likelihood->label() }} · {{ $risk->magnitude->label() }} → {{ $risk->inherentRiskLabel() }} on the spectrum</dd></div>
                        <div class="dl-row"><dt>Controls</dt><dd>{{ $risk->controls_reliance ? 'Reliance planned: '.$risk->controls_description : 'Substantive approach (no reliance on controls)' }}</dd></div>
                    </dl>
                    <div><div class="label">Planned response</div><p class="whitespace-pre-line text-ink-700">{{ $risk->planned_response ?: '—' }}</p></div>
                    @if ($risk->withdrawn_at)<p class="rounded-md bg-navy-50 px-3 py-2 text-xs text-ink-600">Withdrawn {{ local_time($risk->withdrawn_at) }}: {{ $risk->withdrawn_reason }}</p>@endif
                </div>
            </div>

            @if ($risk->is_significant && $risk->isActive())
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Significant risk approval</h2>@if($sig)<x-status :value="$sig->value" />@endif</div>
                    <div class="card-body space-y-3">
                        @if ($pendingApproval)
                            @include('planning.partials.approval', ['approveLabel' => 'Approve as significant risk'])
                        @elseif ($sig === \App\Enums\SignificanceStatus::Approved)
                            @php $a = $risk->approvals->firstWhere('status', \App\Enums\ApprovalStatus::Approved); @endphp
                            <p class="text-sm text-emerald-800">Approved by {{ $a?->decider?->name }} {{ $a?->decided_at ? local_time($a->decided_at) : '' }}.</p>
                        @else
                            @php $r = $risk->approvals->firstWhere('status', \App\Enums\ApprovalStatus::Rejected); @endphp
                            @if ($sig === \App\Enums\SignificanceStatus::Rejected && $r)<p class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">Returned by {{ $r->decider?->name }}: {{ $r->decision_comment }}</p>@endif
                            @if ($canManage)
                                @if (blank($risk->planned_response))
                                    <p class="text-sm text-ink-600">Record the planned response before submitting.</p>
                                @else
                                    <form method="POST" action="{{ route('risks.submit', [$engagement, $risk]) }}" class="flex flex-wrap items-end gap-2">@csrf
                                        <x-field name="comment" label="Note to the engagement partner" class="min-w-[16rem] flex-1" />
                                        <button class="btn-accent">Submit for approval</button></form>
                                @endif
                            @else
                                <p class="text-sm text-ink-500">Not yet submitted for approval.</p>
                            @endif
                        @endif
                    </div>
                </div>
            @endif

            @if ($canManage && $risk->isActive() && $sig !== \App\Enums\SignificanceStatus::Pending && $risk->presumed_key !== 'management_override')
                <form method="POST" action="{{ $risk->presumed_key === 'revenue_recognition' ? route('risks.rebut', [$engagement, $risk]) : route('risks.withdraw', [$engagement, $risk]) }}" class="card card-body flex flex-wrap items-end gap-2">
                    @csrf
                    <x-field name="reason" :label="$risk->presumed_key === 'revenue_recognition' ? 'Rebut the presumption — why fraud in revenue recognition is not a risk for this entity' : 'Withdraw this risk — reason'" class="min-w-[16rem] flex-1" required />
                    <button class="btn-danger">{{ $risk->presumed_key === 'revenue_recognition' ? 'Rebut presumption' : 'Withdraw' }}</button>
                </form>
            @endif
        </div>
        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Approval history</h2></div>
                <ul class="divide-y divide-navy-50 text-sm">
                    @forelse ($risk->approvals as $a)
                        <li class="px-5 py-3"><div class="flex items-center justify-between"><span>{{ $a->requester->name }}</span><x-status :value="$a->status" /></div>
                            <div class="text-xs text-ink-500">{{ local_time($a->requested_at) }}@if($a->decided_at) · {{ $a->decider?->name }} {{ local_time($a->decided_at) }}@endif</div>
                            @if ($a->decision_comment)<p class="mt-1 text-xs text-ink-600">{{ $a->decision_comment }}</p>@endif</li>
                    @empty
                        <li class="px-5 py-3 text-ink-500">None.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-layouts.app>
