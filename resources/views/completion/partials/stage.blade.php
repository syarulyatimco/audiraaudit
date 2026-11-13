{{-- One approval stage: $title, $status (SignificanceStatus|null), $gates, $route, $canSubmit, $approval (pending), $history (approvals of this type), $approveLabel --}}
<div class="card">
    <div class="card-header"><h2 class="card-title">{{ $title }}</h2>@if($status)<x-status :value="$status->value" />@endif</div>
    <div class="card-body space-y-3">
        @include('completion.partials.gates', ['gates' => $gates])
        @if ($approval)
            @include('planning.partials.approval', ['pendingApproval' => $approval, 'approveLabel' => $approveLabel])
        @elseif ($status === \App\Enums\SignificanceStatus::Approved)
            @php $a = $history->firstWhere('status', \App\Enums\ApprovalStatus::Approved); @endphp
            <p class="text-sm text-emerald-800">Approved by {{ $a?->decider?->name }} {{ $a?->decided_at ? local_time($a->decided_at) : '' }}.</p>
        @else
            @php $r = $history->firstWhere('status', \App\Enums\ApprovalStatus::Rejected); @endphp
            @if ($status === \App\Enums\SignificanceStatus::Rejected && $r)<p class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">Returned by {{ $r->decider?->name }}: {{ $r->decision_comment }}</p>@endif
            @if ($canSubmit)
                <form method="POST" action="{{ $route }}" class="flex flex-wrap items-end gap-2 border-t border-navy-50 pt-3">@csrf
                    <x-field name="comment" label="Note to the engagement partner" class="min-w-[14rem] flex-1" />
                    <button class="btn-accent" @disabled(collect($gates)->contains('ok', false))>Submit for approval</button></form>
            @endif
        @endif
    </div>
</div>
