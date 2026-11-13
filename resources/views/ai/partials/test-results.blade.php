@php
    $out = $run->output ?? [];
    $canAccept = ! $engagement->status->isLocked() && \App\Support\Authorization\Permissions::allowsOnEngagement(auth()->user(), $engagement, 'engagement.execute_procedures');
    $plan = $engagement->samplingPlans()->where('uuid', $run->input['subject'] ?? '')->first();
    $untested = $plan ? $plan->sampleItems()->whereNull('result')->with('populationItem')->get()->pluck('populationItem.item_ref')->all() : [];
    $docs = $engagement->documents()->whereIn('uuid', collect($out['results'] ?? [])->pluck('document_id'))->pluck('title', 'uuid');
@endphp
<form method="POST" action="{{ route('ai-runs.accept-test-results', [$engagement, $run]) }}" class="card">
    @csrf
    <div class="card-header"><div><h3 class="card-title">AI-drafted test results ({{ count($out['results'] ?? []) }})</h3><p class="text-xs text-ink-500">{{ $out['summary'] ?? '' }}</p></div>
        @if ($canAccept && ! empty($out['results']) && $plan?->status?->value === 'selected')<button class="btn-accent btn-sm">Record selected results</button>@endif</div>
    @include('ai.partials.guard-note')
    <p class="px-5 pt-3 text-xs text-ink-500">Each result quotes the evidence it relies on. Check the quote against the document before accepting: you become the tester of record.</p>
    <ul class="divide-y divide-navy-50">
        @forelse ($out['results'] ?? [] as $i => $r)
            @php $open = in_array($r['item_ref'], $untested, true); @endphp
            <li class="flex gap-3 px-5 py-3 text-sm">
                @if ($canAccept && $open)<input type="checkbox" name="selected[]" value="{{ $i }}" class="checkbox mt-1">@endif
                <div class="flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-2"><span class="font-mono text-xs font-semibold">{{ $r['item_ref'] }}</span><x-status :value="$r['result']" />
                        @if ($r['audited_amount_sen'] !== null)<span class="tabular-nums text-xs">audited RM {{ amount($r['audited_amount_sen'], false) }}</span>@endif
                        @if (! $open)<span class="badge badge-neutral">Already tested</span>@endif</div>
                    <p class="text-ink-700">{{ $r['note'] }}</p>
                    <blockquote class="border-l-2 border-teal-300 pl-3 text-xs text-ink-600">“{{ $r['evidence_quote'] }}” <span class="text-ink-500">— {{ $docs[$r['document_id']] ?? 'document' }}</span></blockquote>
                </div>
            </li>
        @empty
            <li class="px-5 py-4 text-sm text-ink-500">No results could be drafted from the available evidence.</li>
        @endforelse
    </ul>
    @if (! empty($out['no_evidence']))<p class="border-t border-navy-50 px-5 py-3 text-xs text-ink-500">No usable evidence found for: {{ implode(', ', $out['no_evidence']) }}. Upload or link the evidence, or test these items yourself.</p>@endif
</form>
