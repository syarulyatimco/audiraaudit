<x-layouts.app :title="$finding->reference.' · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-fieldwork-nav :engagement="$engagement" />
    @php
        $sig = $finding->significance_status;
        $open = $finding->status === \App\Enums\FindingStatus::Open;
        $editable = $canManage && $open && $sig !== \App\Enums\SignificanceStatus::Pending;
    @endphp
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card">
                <div class="card-header">
                    <div><a href="{{ route('findings.index', $engagement) }}" class="text-xs text-ink-500 hover:text-navy-900">← Findings</a>
                        <h2 class="mt-1 text-lg font-semibold">{{ $finding->reference }} · {{ $finding->title }}</h2>
                        <div class="mt-1 flex flex-wrap gap-1">
                            <span class="badge badge-neutral">{{ $finding->type->label() }}</span>
                            @if ($finding->is_significant)<span class="badge badge-danger">Significant</span>@endif
                            @if ($finding->source === 'ai')<span class="badge badge-teal">AI draft</span>@elseif ($finding->source === 'sample')<span class="badge badge-info">From sample</span>@endif
                        </div></div>
                    <div class="flex items-center gap-2"><x-status :value="$finding->status" />@if ($editable)<a href="{{ route('findings.edit', [$engagement, $finding]) }}" class="btn-secondary btn-sm">Edit</a>@endif</div>
                </div>
                <div class="card-body space-y-4 text-sm">
                    <p class="whitespace-pre-line text-ink-700">{{ $finding->description }}</p>
                    <dl>
                        @if ($finding->fsli)<div class="dl-row"><dt>Line item</dt><dd>{{ $finding->fsli->name }}</dd></div>@endif
                        @if ($finding->procedure)<div class="dl-row"><dt>Procedure</dt><dd><a class="link" href="{{ route('programme.show', [$engagement, $finding->procedure]) }}">{{ $finding->procedure->reference }} {{ $finding->procedure->title }}</a></dd></div>@endif
                        @if ($finding->samplingPlan)<div class="dl-row"><dt>Sample</dt><dd><a class="link" href="{{ route('sampling.show', [$engagement, $finding->samplingPlan]) }}">{{ $finding->samplingPlan->reference }} {{ $finding->samplingPlan->title }}</a></dd></div>@endif
                        @if ($finding->isMisstatement())
                            <div class="dl-row"><dt>Class</dt><dd>{{ $finding->misstatement_class->label() }}</dd></div>
                            <div class="dl-row"><dt>Misstatement</dt><dd class="tabular-nums">RM {{ amount($finding->amount_sen) }} <span class="text-xs font-normal text-ink-500">{{ $finding->amount_sen > 0 ? 'overstatement' : 'understatement' }}</span></dd></div>
                        @endif
                        @if ($finding->deficiency_severity)
                            <div class="dl-row"><dt>Evaluation</dt><dd>{{ $finding->deficiency_severity->label() }}</dd></div>
                            <div class="dl-row"><dt>Communicate</dt><dd>{{ $finding->deficiency_severity === \App\Enums\DeficiencySeverity::Significant ? 'In writing to those charged with governance' : ($finding->report_to_management ? 'To management' : 'Not planned') }}</dd></div>
                        @endif
                        <div class="dl-row"><dt>Recorded</dt><dd>{{ $finding->creator->name }} <span class="text-xs font-normal text-ink-500">{{ local_time($finding->created_at) }}</span></dd></div>
                    </dl>
                    @foreach (['cause' => 'Cause', 'recommendation' => 'Recommendation', 'management_response' => "Management's response"] as $k => $l)
                        @if ($finding->$k)<div><div class="label">{{ $l }}</div><p class="whitespace-pre-line text-ink-700">{{ $finding->$k }}</p></div>@endif
                    @endforeach
                    @if ($finding->resolution)<p class="rounded-md bg-navy-50 px-3 py-2 text-xs text-ink-600">{{ $finding->status->label() }}: {{ $finding->resolution }}</p>@endif
                </div>
            </div>

            @if ($finding->isMisstatement() && $finding->status !== \App\Enums\FindingStatus::Withdrawn)
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Correction by management</h2>@if($finding->correction_status)<x-status :value="$finding->correction_status" />@endif</div>
                    <div class="card-body space-y-3 text-sm">
                        @if ($finding->adjustment)<p>Corrected by <a class="link" href="{{ route('adjustments.show', [$engagement, $finding->adjustment]) }}">{{ $finding->adjustment->number }}</a> ({{ $finding->adjustment->status->label() }}).</p>@endif
                        @if ($finding->uncorrected_reason)<div><div class="label">Management's reason for not correcting</div><p class="whitespace-pre-line text-ink-700">{{ $finding->uncorrected_reason }}</p></div>@endif
                        @if ($canManage && $open)
                            <form method="POST" action="{{ route('findings.correction', [$engagement, $finding]) }}" class="space-y-3 border-t border-navy-50 pt-3" x-data="{ s: @js(old('correction_status', $finding->correction_status?->value ?? 'proposed')) }">@csrf
                                <x-select name="correction_status" label="Status" :options="\App\Enums\CorrectionStatus::options()" :value="$finding->correction_status" x-model="s" class="max-w-xs" />
                                <div x-show="s === 'corrected'">
                                    <x-select name="adjustment" label="Posted adjustment that corrects it" :options="$postedAdjustments->mapWithKeys(fn ($a) => [$a->uuid => $a->number.' — '.\Illuminate\Support\Str::limit($a->description, 50)])->all()" :value="$finding->adjustment?->uuid" placeholder="Choose…" />
                                    @if ($postedAdjustments->isEmpty())<p class="hint">No posted adjustments yet. <a class="link" href="{{ route('adjustments.create', $engagement) }}">Prepare one</a>.</p>@endif
                                </div>
                                <div x-show="s === 'uncorrected'"><x-field name="uncorrected_reason" label="Management's reason" type="textarea" rows="2" :value="$finding->uncorrected_reason" /></div>
                                <div class="flex justify-end"><button class="btn-secondary">Save</button></div>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

            @if ($finding->is_significant && $finding->status !== \App\Enums\FindingStatus::Withdrawn)
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Significant finding approval</h2>@if($sig)<x-status :value="$sig->value" />@endif</div>
                    <div class="card-body space-y-3">
                        @if ($pendingApproval)
                            @include('planning.partials.approval', ['approveLabel' => 'Approve significant finding'])
                        @elseif ($sig === \App\Enums\SignificanceStatus::Approved)
                            @php $a = $finding->approvals->firstWhere('status', \App\Enums\ApprovalStatus::Approved); @endphp
                            <p class="text-sm text-emerald-800">Approved by {{ $a?->decider?->name }} {{ $a?->decided_at ? local_time($a->decided_at) : '' }}.</p>
                        @else
                            @php $r = $finding->approvals->firstWhere('status', \App\Enums\ApprovalStatus::Rejected); @endphp
                            @if ($sig === \App\Enums\SignificanceStatus::Rejected && $r)<p class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">Returned by {{ $r->decider?->name }}: {{ $r->decision_comment }}</p>@endif
                            @if ($canManage && $open)
                                <form method="POST" action="{{ route('findings.submit', [$engagement, $finding]) }}" class="flex flex-wrap items-end gap-2">@csrf
                                    <x-field name="comment" label="Note to the engagement partner" class="min-w-[16rem] flex-1" />
                                    <button class="btn-accent">Submit for approval</button></form>
                            @else
                                <p class="text-sm text-ink-500">Not yet submitted for approval.</p>
                            @endif
                        @endif
                    </div>
                </div>
            @endif

            @if ($editable)
                <form method="POST" class="card card-body flex flex-wrap items-end gap-2" x-data="{ action: 'resolve' }" :action="action === 'resolve' ? @js(route('findings.resolve', [$engagement, $finding])) : @js(route('findings.withdraw', [$engagement, $finding]))">
                    @csrf
                    <x-field name="resolution" label="Close this finding — resolution, or reason for withdrawing" class="min-w-[16rem] flex-1" required />
                    <button class="btn-secondary" @click="action = 'resolve'">Resolve</button>
                    <button class="btn-danger" @click="action = 'withdraw'">Withdraw</button>
                </form>
            @endif
        </div>
        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Approval history</h2></div>
                <ul class="divide-y divide-navy-50 text-sm">
                    @forelse ($finding->approvals as $a)
                        <li class="px-5 py-3"><div class="flex items-center justify-between"><span>{{ $a->requester->name }}</span><x-status :value="$a->status" /></div>
                            <div class="text-xs text-ink-500">{{ local_time($a->requested_at) }}@if($a->decided_at) · {{ $a->decider?->name }} {{ local_time($a->decided_at) }}@endif</div>
                            @if ($a->decision_comment)<p class="mt-1 text-xs text-ink-600">{{ $a->decision_comment }}</p>@endif</li>
                    @empty
                        <li class="px-5 py-3 text-ink-500">None.</li>
                    @endforelse
                </ul>
            </div>
            @if ($finding->isMisstatement())
                <a href="{{ route('findings.differences', $engagement) }}" class="card block px-5 py-4 text-sm hover:border-teal-300"><div class="font-medium text-navy-900">Summary of audit differences →</div><p class="text-xs text-ink-500">Uncorrected misstatements against materiality.</p></a>
            @endif
        </div>
    </div>
</x-layouts.app>
