@php
    $out = $run->output ?? [];
    $accounts = app(\App\Services\TrialBalance\LeadScheduleService::class)->accounts($engagement);
    $fslis = $engagement->fslis()->get()->keyBy('code');
    $open = collect($out['suggestions'] ?? [])->filter(fn ($s) => isset($accounts[$s['account_code']]) && $accounts[$s['account_code']]['fsli_id'] === null);
    $canMap = ! $engagement->status->isLocked() && \App\Support\Authorization\Permissions::allowsOnEngagement(auth()->user(), $engagement, 'engagement.map_accounts');
@endphp
<form method="POST" action="{{ route('ai-runs.accept-mappings', [$engagement, $run]) }}" class="card">
    @csrf
    <div class="card-header">
        <div><h3 class="card-title">AI mapping suggestions ({{ $open->count() }} open)</h3>@if (! empty($out['summary']))<p class="text-xs text-ink-500">{{ $out['summary'] }}</p>@endif</div>
        @if ($canMap && $open->isNotEmpty())<button class="btn-accent btn-sm">Accept selected</button>@endif
    </div>
    @if ($open->isEmpty())
        <p class="card-body text-sm text-ink-500">No open suggestions: accounts have since been mapped, or the agent made none.</p>
    @else
        <div class="max-h-[28rem] overflow-y-auto">
            <table class="table text-sm">
                <thead><tr><th class="w-8"></th><th>Account</th><th class="text-right">Current year</th><th>Suggested FSLI</th><th>Confidence</th><th>Rationale</th></tr></thead>
                <tbody>
                @foreach ($open as $s)
                    <tr>
                        <td>@if($canMap)<input type="checkbox" name="codes[]" value="{{ $s['account_code'] }}" class="checkbox" @checked($s['confidence'] === 'high')>@endif</td>
                        <td><span class="font-mono text-xs font-semibold">{{ $s['account_code'] }}</span> {{ $accounts[$s['account_code']]['name'] ?? '' }}</td>
                        <td class="text-right tabular-nums">{{ amount($accounts[$s['account_code']]['cy'] ?? 0) }}</td>
                        <td>{{ $fslis[$s['fsli_code']]->name ?? $s['fsli_code'] }}</td>
                        <td><span class="badge {{ ['high' => 'badge-success', 'medium' => 'badge-warn', 'low' => 'badge-neutral'][$s['confidence']] }}">{{ $s['confidence'] }}</span></td>
                        <td class="text-xs text-ink-600">{{ $s['rationale'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if (! empty($out['needs_judgement']))
        <div class="border-t border-navy-50 px-5 py-3">
            <div class="text-2xs font-semibold uppercase tracking-wider text-ink-500">Left for your judgement</div>
            <ul class="mt-1 space-y-1 text-sm">@foreach ($out['needs_judgement'] as $n)<li><span class="font-mono text-xs font-semibold">{{ $n['account_code'] }}</span> {{ $accounts[$n['account_code']]['name'] ?? '' }} — <span class="text-ink-600">{{ $n['reason'] }}</span></li>@endforeach</ul>
        </div>
    @endif
</form>
