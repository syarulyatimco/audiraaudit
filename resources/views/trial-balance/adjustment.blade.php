<x-layouts.app :title="$adjustment->number">
    <x-engagement-nav :engagement="$engagement" />
    <x-tb-nav :engagement="$engagement" />
    @php $isDraft = $adjustment->status === \App\Enums\AdjustmentStatus::Draft; $isPosted = $adjustment->status === \App\Enums\AdjustmentStatus::Posted; @endphp
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <div class="card-header">
                <div><h2 class="text-lg font-semibold text-navy-900">{{ $adjustment->number }} <span class="text-sm font-normal text-ink-500">· {{ $adjustment->type->label() }}</span></h2></div>
                @php $tone = ['draft' => 'warn', 'posted' => 'success', 'voided' => 'neutral'][$adjustment->status->value]; @endphp
                <span class="badge badge-dot badge-{{ $tone }}">{{ $adjustment->status->label() }}</span>
            </div>
            <p class="card-body whitespace-pre-line text-sm text-ink-700">{{ $adjustment->description }}</p>
            <table class="table text-sm">
                <thead><tr><th>Account</th><th>Note</th><th class="text-right">Debit</th><th class="text-right">Credit</th></tr></thead>
                <tbody>
                @foreach ($adjustment->lines as $l)
                    <tr><td><span class="font-mono text-xs font-semibold">{{ $l->account_code }}</span> {{ $accountNames[$l->account_code] ?? '' }}</td><td class="text-ink-500">{{ $l->description }}</td>
                        <td class="text-right tabular-nums">{{ amount($l->debit_sen) }}</td><td class="text-right tabular-nums">{{ amount($l->credit_sen) }}</td></tr>
                @endforeach
                </tbody>
                <tfoot><tr class="border-t-2 border-navy-100 font-semibold"><td class="px-4 py-3" colspan="2">Total</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ amount($adjustment->totalDebitSen(), false) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ amount($adjustment->totalCreditSen(), false) }}</td></tr></tfoot>
            </table>
        </div>
        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Trail</h2></div>
                <dl class="card-body">
                    <div class="dl-row"><dt>Prepared</dt><dd>{{ $adjustment->preparer->name }}<br><span class="text-xs font-normal text-ink-500">{{ local_time($adjustment->created_at) }}</span></dd></div>
                    <div class="dl-row"><dt>Posted</dt><dd>{{ $adjustment->poster?->name ?? '—' }}@if($adjustment->posted_at)<br><span class="text-xs font-normal text-ink-500">{{ local_time($adjustment->posted_at) }}</span>@endif</dd></div>
                    @if ($adjustment->voided_at)<div class="dl-row"><dt>Voided</dt><dd>{{ $adjustment->voider?->name }}<br><span class="text-xs font-normal text-ink-500">{{ $adjustment->void_reason }}</span></dd></div>@endif
                </dl>
            </div>
            @if ($adjustment->findings->isNotEmpty())
                <div class="card"><div class="card-header"><h2 class="card-title">Corrects findings</h2></div>
                    <ul class="divide-y divide-navy-50 text-sm">@foreach ($adjustment->findings as $f)<li class="px-5 py-2"><a class="link" href="{{ route('findings.show', [$engagement, $f]) }}">{{ $f->reference }} {{ $f->title }}</a></li>@endforeach</ul></div>
            @endif
            @if (! $engagement->status->isLocked())
                <div class="card card-body space-y-3">
                    @if ($isDraft && $canEdit && $adjustment->approval_status !== \App\Enums\SignificanceStatus::Pending)<a href="{{ route('adjustments.edit', [$engagement, $adjustment]) }}" class="btn-secondary w-full">Edit draft</a>@endif
                    @php $approved = $adjustment->approval_status === \App\Enums\SignificanceStatus::Approved; @endphp
                    @if ($isDraft && $needsApproval)
                        <div class="rounded-md border border-navy-100 p-3 text-sm">
                            <div class="font-medium text-navy-900">Material adjustment</div>
                            <p class="text-xs text-ink-500">{{ $threshold === null ? 'Materiality is not approved yet, so every adjustment needs engagement-partner approval before posting.' : 'Total RM '.amount($adjustment->totalDebitSen(), false).' is at or above the threshold of RM '.amount($threshold, false).'. Engagement-partner approval is needed before posting.' }}</p>
                            @if ($pendingApproval)
                                <div class="mt-3">@include('planning.partials.approval', ['approveLabel' => 'Approve adjustment'])</div>
                            @elseif ($approved)
                                @php $a = $adjustment->approvals->firstWhere('status', \App\Enums\ApprovalStatus::Approved); @endphp
                                <p class="mt-2 text-xs text-emerald-800">Approved by {{ $a?->decider?->name }}.</p>
                            @elseif ($canEdit)
                                @if ($adjustment->approval_status === \App\Enums\SignificanceStatus::Rejected)@php $r = $adjustment->approvals->firstWhere('status', \App\Enums\ApprovalStatus::Rejected); @endphp<p class="mt-2 text-xs text-red-700">Returned: {{ $r?->decision_comment }}</p>@endif
                                <form method="POST" action="{{ route('adjustments.approval', [$engagement, $adjustment]) }}" class="mt-2 space-y-2">@csrf
                                    <input name="comment" class="input text-sm" placeholder="Note to the engagement partner"><button class="btn-accent w-full">Request approval</button></form>
                            @endif
                        </div>
                    @endif
                    @if ($isDraft && $canPost && (! $needsApproval || $approved))
                        <form method="POST" action="{{ route('adjustments.post', [$engagement, $adjustment]) }}" onsubmit="return confirm('Post {{ $adjustment->number }} to the adjusted trial balance?')">@csrf<button class="btn-accent w-full">Post to adjusted TB</button></form>
                    @elseif ($isDraft && $adjustment->prepared_by === auth()->id() && (! $needsApproval || $approved))
                        <p class="text-xs text-ink-500">Another engagement manager or the engagement partner must post this adjustment.</p>
                    @endif
                    @if (($isDraft && $canEdit) || ($isPosted && $canPost))
                        <form method="POST" action="{{ route('adjustments.void', [$engagement, $adjustment]) }}" class="space-y-2" x-data="{ open: false }">
                            @csrf
                            <button type="button" class="btn-danger w-full" @click="open = true" x-show="!open">Void…</button>
                            <div x-show="open" x-cloak class="space-y-2"><input name="reason" class="input text-sm" placeholder="Reason for voiding" required><button class="btn-danger w-full">Confirm void</button></div>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
