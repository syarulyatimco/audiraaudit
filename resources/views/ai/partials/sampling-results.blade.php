@php
    $out = $run->output ?? [];
    $plan = $engagement->samplingPlans()->where('uuid', $run->input['subject'] ?? '')->first();
    $canUse = ($out['usable'] ?? false) && $plan?->isEditable() && ! $engagement->status->isLocked()
        && \App\Support\Authorization\Permissions::allowsOnEngagement(auth()->user(), $engagement, 'engagement.manage_sampling');
@endphp
<div class="card">
    <div class="card-header"><h3 class="card-title">AI parameter recommendation{{ $plan ? ' · '.$plan->reference : '' }}</h3><span class="text-xs text-ink-500">AUDIRA computes the sample size</span></div>
    @include('ai.partials.guard-note')
    <div class="card-body space-y-3 text-sm">
        <dl>
            <div class="dl-row"><dt>Confidence level</dt><dd>{{ $out['confidence_level'] ?? '—' }}%</dd></div>
            @if (($out['tolerable_misstatement_sen'] ?? null) !== null)
                <div class="dl-row"><dt>Tolerable misstatement</dt><dd>RM {{ amount($out['tolerable_misstatement_sen']) }}</dd></div>
                <div class="dl-row"><dt>Expected misstatement</dt><dd>RM {{ amount($out['expected_misstatement_sen'] ?? 0) }}</dd></div>
                <div class="dl-row"><dt>Key item threshold</dt><dd>{{ ($out['key_item_threshold_sen'] ?? null) !== null ? 'RM '.amount($out['key_item_threshold_sen']) : '—' }}</dd></div>
            @else
                <div class="dl-row"><dt>Tolerable / expected deviation</dt><dd>{{ $out['tolerable_deviation_rate'] ?? '—' }}% / {{ $out['expected_deviation_rate'] ?? 0 }}%</dd></div>
            @endif
        </dl>
        <p class="whitespace-pre-line text-ink-700">{{ $out['rationale'] ?? '' }}</p>
        @if (! empty($out['method_comment']))<p class="text-xs text-ink-600"><span class="font-semibold">Method:</span> {{ $out['method_comment'] }}</p>@endif
        @foreach ($out['citations'] ?? [] as $c)<p class="text-xs"><span class="font-medium">{{ $c['citation'] ?? '#'.$c['paragraph_id'] }}</span> — <span class="text-ink-500">{{ $c['relevance'] }}</span></p>@endforeach
    </div>
    @if ($canUse)
        <form method="POST" action="{{ route('ai-runs.accept-sampling', [$engagement, $run]) }}" class="flex justify-end border-t border-navy-100/70 px-5 py-3">@csrf<button class="btn-accent">Apply to draft and recalculate</button></form>
    @endif
</div>
