@php
    $out = $run->output ?? [];
    $hasApproved = (bool) $engagement->currentMateriality();
    $pending = $engagement->materialityAssessments()->where('status', 'pending_approval')->exists();
    $canUse = ($out['usable'] ?? false) && ! $pending && ! $engagement->status->isLocked()
        && \App\Support\Authorization\Permissions::allowsOnEngagement(auth()->user(), $engagement, 'engagement.prepare_materiality');
@endphp
<div class="card">
    <div class="card-header"><div><h3 class="card-title">AI materiality recommendation</h3><p class="text-xs text-ink-500">{{ $out['summary'] ?? '' }}</p></div><span class="text-xs text-ink-500">A recommendation — not approved materiality</span></div>
    @include('ai.partials.guard-note')
    <div class="card-body space-y-4 text-sm">
        @if (isset($out['computed']))
            <div class="grid gap-3 sm:grid-cols-3">
                <div><div class="label">Overall</div><div class="tabular-nums">RM {{ amount($out['computed']['om_sen']) }}</div><div class="text-xs text-ink-500">{{ $out['om_percentage'] }}% of {{ \App\Enums\MaterialityBenchmark::tryFrom($out['benchmark'])?->label() }} RM {{ amount($out['benchmark_amount_sen']) }} ({{ str_replace('_', ' ', $out['benchmark_year']) }})</div></div>
                <div><div class="label">Performance</div><div class="tabular-nums">RM {{ amount($out['computed']['pm_sen']) }}</div><div class="text-xs text-ink-500">{{ $out['pm_percentage'] }}% of overall</div></div>
                <div><div class="label">Clearly trivial</div><div class="tabular-nums">RM {{ amount($out['computed']['ct_sen']) }}</div><div class="text-xs text-ink-500">{{ $out['ct_percentage'] }}% of overall</div></div>
            </div>
        @endif
        <div><div class="label">Rationale</div><p class="whitespace-pre-line text-ink-700">{{ $out['rationale'] ?? '' }}</p></div>
        @if (! empty($out['alternatives']))
            <div><div class="label">Alternatives considered</div><ul class="list-inside list-disc text-ink-700">@foreach ($out['alternatives'] as $a)<li><span class="font-medium">{{ $a['benchmark'] }}</span> — {{ $a['reason_rejected'] }}</li>@endforeach</ul></div>
        @endif
        @if (! empty($out['specifics']))
            <div><div class="label">Specific materiality</div>@foreach ($out['specifics'] as $s)<p>{{ $s['area'] }} — RM {{ amount($s['amount_sen']) }}: <span class="text-ink-600">{{ $s['rationale'] }}</span></p>@endforeach</div>
        @endif
        <div><div class="label">Cited library paragraphs</div>
            @forelse ($out['citations'] ?? [] as $c)<p><span class="font-medium">{{ $c['citation'] ?? '#'.$c['paragraph_id'] }}</span> — <span class="text-ink-600">{{ $c['relevance'] }}</span></p>
            @empty<p class="text-ink-500">None. The Audit Library returned no relevant published paragraphs.</p>@endforelse
        </div>
    </div>
    @if ($canUse)
        <form method="POST" action="{{ route('ai-runs.accept-materiality', [$engagement, $run]) }}" class="flex flex-wrap items-end justify-end gap-2 border-t border-navy-100/70 px-5 py-3">
            @csrf
            @if ($hasApproved)<x-field name="revision_reason" label="Reason for revision" class="min-w-[16rem] flex-1" required />@endif
            <button class="btn-accent">Use as draft</button>
        </form>
    @endif
</div>
