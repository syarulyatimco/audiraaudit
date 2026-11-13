<x-layouts.app :title="'Completion · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-completion-nav :engagement="$engagement" />
    @php
        $cs = $completion->conclusions_status;
        $ps = $completion->completion_status;
        $checklistLocked = in_array($cs, [\App\Enums\SignificanceStatus::Pending, \App\Enums\SignificanceStatus::Approved], true);
        $conclusionsLocked = $cs === \App\Enums\SignificanceStatus::Pending || in_array($ps, [\App\Enums\SignificanceStatus::Pending, \App\Enums\SignificanceStatus::Approved], true);
        $byType = fn ($t) => $completion->approvals->filter(fn ($a) => $a->type->value === $t);
        $pendingFor = fn ($t) => $pendingApproval && $pendingApproval->type->value === $t ? $pendingApproval : null;
        $done = $items->where('status', '!=', \App\Enums\CompletionItemStatus::Open)->count();
    @endphp
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @include('ai.partials.panel', ['agent' => 'completion', 'task' => 'draft_checklist', 'title' => 'AI completion drafting',
                'blurb' => 'The Completion agent drafts responses to open checklist items from the engagement record (findings, misstatements, procedures, requests). It never marks an item done or comments on the opinion.', 'show' => $canManage && ! $checklistLocked])

            <div class="card">
                <div class="card-header"><div><h2 class="card-title">Completion checklist</h2><p class="text-xs text-ink-500">{{ $done }} of {{ $items->count() }} answered</p></div></div>
                <ul class="divide-y divide-navy-50">
                    @foreach ($items as $it)
                        <li class="px-5 py-4 text-sm" x-data="{ open: false }">
                            <div class="flex items-start justify-between gap-3">
                                <div><div class="text-2xs font-semibold uppercase tracking-wider text-teal-700">{{ $it->section }}</div><div class="font-medium text-navy-900">{{ $it->title }}</div>
                                    @if ($it->response)<p class="mt-1 whitespace-pre-line text-ink-700">{{ $it->response }}</p>@endif
                                    @if ($it->completed_by)<p class="mt-1 text-xs text-ink-500">{{ $it->completer?->name }} · {{ local_time($it->completed_at) }}@if($it->source === 'ai') · from an AI draft @endif</p>@elseif($it->response && $it->source === 'ai')<p class="mt-1 text-xs text-teal-700">AI draft — review and mark done</p>@endif
                                </div>
                                <div class="flex shrink-0 items-center gap-2"><x-status :value="$it->status" />@if ($canManage && ! $checklistLocked)<button type="button" class="btn-secondary btn-sm" @click="open = !open">Respond</button>@endif</div>
                            </div>
                            @if ($canManage && ! $checklistLocked)
                                <form method="POST" action="{{ route('completion.item', [$engagement, $it]) }}" class="mt-3 space-y-2" x-show="open" x-cloak>@csrf
                                    <textarea name="response" rows="3" class="input text-sm" placeholder="Work done, evidence and conclusion">{{ $it->response }}</textarea>
                                    <div class="flex items-center justify-end gap-2">
                                        <select name="status" class="input w-auto py-1 text-sm">@foreach (\App\Enums\CompletionItemStatus::options() as $k => $l)<option value="{{ $k }}" @selected($it->status->value === $k || ($it->status->value === 'open' && $k === 'done'))>{{ $l }}</option>@endforeach</select>
                                        <button class="btn-primary btn-sm">Save</button>
                                    </div>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            <form method="POST" action="{{ route('completion.conclusions', $engagement) }}" class="card">
                @csrf @method('PUT')
                <div class="card-header"><h2 class="card-title">Final conclusions</h2></div>
                <div class="card-body space-y-4">
                    @if ($canManage && ! $conclusionsLocked)
                        <x-field name="misstatements_conclusion" label="Uncorrected misstatements — evaluation individually and in aggregate" type="textarea" rows="3" :value="$completion->misstatements_conclusion" />
                        <x-field name="going_concern_conclusion" label="Going concern — conclusion" type="textarea" rows="3" :value="$completion->going_concern_conclusion" />
                        <x-field name="overall_conclusion" label="Overall conclusion — sufficient appropriate evidence obtained" type="textarea" rows="3" :value="$completion->overall_conclusion" />
                        <p class="text-xs text-ink-500">Changing the conclusions after the partner's decision requires a fresh approval and a fresh engagement quality review.</p>
                    @else
                        @foreach (['misstatements_conclusion' => 'Uncorrected misstatements', 'going_concern_conclusion' => 'Going concern', 'overall_conclusion' => 'Overall'] as $k => $l)
                            <div><div class="label">{{ $l }}</div><p class="whitespace-pre-line text-sm text-ink-700">{{ $completion->$k ?: '—' }}</p></div>
                        @endforeach
                    @endif
                </div>
                @if ($canManage && ! $conclusionsLocked)<div class="flex justify-end border-t border-navy-100/70 px-5 py-3"><button class="btn-primary">Save conclusions</button></div>@endif
            </form>

            @include('ai.partials.panel', ['agent' => 'quality_review', 'task' => 'pre_review', 'aiRun' => $qrRun, 'aiUnavailable' => $qrUnavailable, 'title' => 'AI quality pre-review',
                'blurb' => 'The Quality Review agent checks the file for missing sign-offs, open review notes, procedures without working papers and unlinked evidence, and drafts review notes. It is advisory only.', 'show' => $canManage || $canEqr])
        </div>

        <div class="space-y-6">
            <a href="{{ route('findings.differences', $engagement) }}" class="card block px-5 py-4 text-sm hover:border-teal-300">
                <div class="text-2xs font-semibold uppercase tracking-wider text-ink-500">Uncorrected misstatements</div>
                <div class="mt-1 text-xl font-semibold tabular-nums text-navy-900">RM {{ amount($sad['net_sen']) }}</div>
                <div class="text-xs text-ink-500">Overall materiality RM {{ $sad['om_sen'] !== null ? amount($sad['om_sen']) : '—' }} · summary of audit differences →</div>
            </a>

            @include('completion.partials.stage', ['title' => '1 · Final conclusions', 'status' => $cs, 'gates' => $conclusionsGates, 'route' => route('completion.submit', [$engagement, 'conclusions']),
                'canSubmit' => $canManage, 'approval' => $pendingFor('final_conclusions'), 'history' => $byType('final_conclusions'), 'approveLabel' => 'Approve final conclusions'])

            <div class="card">
                <div class="card-header"><h2 class="card-title">2 · Engagement quality review</h2>@if($completion->eqr_completed_at)<span class="badge badge-success">Completed</span>@endif</div>
                <div class="card-body space-y-3 text-sm">
                    @if (! $eqrRequired)
                        <p class="text-ink-500">No engagement quality reviewer is assigned to this engagement.</p>
                    @elseif ($completion->eqr_completed_at)
                        <p class="whitespace-pre-line text-ink-700">{{ $completion->eqr_conclusion }}</p>
                        <p class="text-xs text-ink-500">{{ $completion->eqrReviewer?->name }} · {{ local_time($completion->eqr_completed_at) }}</p>
                    @elseif ($canEqr && $cs === \App\Enums\SignificanceStatus::Approved)
                        <form method="POST" action="{{ route('completion.eqr', $engagement) }}" class="space-y-2">@csrf
                            <x-field name="eqr_conclusion" label="Conclusion of the engagement quality review" type="textarea" rows="3" required />
                            <button class="btn-accent w-full">Record review as completed</button></form>
                    @else
                        <p class="text-ink-500">The engagement quality reviewer records the review after the final conclusions are approved.</p>
                    @endif
                </div>
            </div>

            @if ($completion->exists)
                @include('completion.partials.stage', ['title' => '3 · Completion', 'status' => $ps, 'gates' => $completionGates, 'route' => route('completion.submit', [$engagement, 'completion']),
                    'canSubmit' => $canManage, 'approval' => $pendingFor('completion'), 'history' => $byType('completion'), 'approveLabel' => 'Approve completion'])
            @endif
            @if ($ps === \App\Enums\SignificanceStatus::Approved)
                <a href="{{ route('reporting.report', $engagement) }}" class="btn-primary w-full">Continue to the audit report →</a>
            @endif
        </div>
    </div>
</x-layouts.app>
