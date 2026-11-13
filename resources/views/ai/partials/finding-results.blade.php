@php
    $out = $run->output ?? [];
    $canAccept = ! $engagement->status->isLocked() && \App\Support\Authorization\Permissions::allowsOnEngagement(auth()->user(), $engagement, 'engagement.manage_findings');
    $taken = \App\Models\Finding::where('ai_run_id', $run->id)->pluck('title')->all();
@endphp
<form method="POST" action="{{ route('ai-runs.accept-findings', [$engagement, $run]) }}" class="card">
    @csrf
    <div class="card-header"><div><h3 class="card-title">AI-drafted findings ({{ count($out['findings'] ?? []) }})</h3><p class="text-xs text-ink-500">{{ $out['summary'] ?? '' }}</p></div>
        @if ($canAccept && ! empty($out['findings']))<button class="btn-accent btn-sm">Record selected</button>@endif</div>
    @include('ai.partials.guard-note')
    <ul class="divide-y divide-navy-50">
        @forelse ($out['findings'] ?? [] as $i => $f)
            @php $done = in_array($f['title'], $taken, true); @endphp
            <li class="flex gap-3 px-5 py-4 text-sm">
                @if ($canAccept && ! $done)<input type="checkbox" name="selected[]" value="{{ $i }}" class="checkbox mt-1" checked>@endif
                <div class="flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-2"><span class="font-semibold text-navy-900">{{ $f['title'] }}</span>
                        <span class="badge badge-neutral">{{ \App\Enums\FindingType::from($f['type'])->label() }}{{ $f['misstatement_class'] ? ' · '.\App\Enums\MisstatementClass::from($f['misstatement_class'])->label() : '' }}{{ $f['deficiency_severity'] ? ' · proposed: '.\App\Enums\DeficiencySeverity::from($f['deficiency_severity'])->label() : '' }}</span>
                        @if ($f['amount_sen'] !== null)<span class="tabular-nums">RM {{ amount($f['amount_sen']) }}</span>@endif
                        @if ($done)<span class="badge badge-neutral">Recorded</span>@endif</div>
                    <p class="text-xs text-ink-500">{{ collect([$f['fsli_code'], $f['procedure_reference'], $f['plan_reference']])->filter()->join(' · ') }}</p>
                    <p class="whitespace-pre-line text-ink-700">{{ $f['description'] }}</p>
                    @if ($f['recommendation'])<p class="text-xs text-ink-600"><span class="font-medium">Recommendation:</span> {{ $f['recommendation'] }}</p>@endif
                </div>
            </li>
        @empty
            <li class="px-5 py-4 text-sm text-ink-500">No new findings proposed.</li>
        @endforelse
    </ul>
</form>
