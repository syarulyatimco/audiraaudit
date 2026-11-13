@php
    $out = $run->output ?? [];
    $canAccept = ! $engagement->status->isLocked() && \App\Support\Authorization\Permissions::allowsOnEngagement(auth()->user(), $engagement, 'engagement.manage_risks');
    $existing = $engagement->risks()->active()->pluck('title')->map(fn ($t) => mb_strtolower(trim($t)))->all();
    $fslis = $engagement->fslis()->pluck('name', 'code');
@endphp
<form method="POST" action="{{ route('ai-runs.accept-risks', [$engagement, $run]) }}" class="card">
    @csrf
    <div class="card-header">
        <div><h3 class="card-title">AI-proposed risks ({{ count($out['risks'] ?? []) }})</h3><p class="text-xs text-ink-500">{{ $out['summary'] ?? '' }}</p></div>
        @if ($canAccept && ! empty($out['risks']))<button class="btn-accent btn-sm">Add selected to register</button>@endif
    </div>
    @include('ai.partials.guard-note')
    <ul class="divide-y divide-navy-50">
        @forelse ($out['risks'] ?? [] as $i => $r)
            @php $dup = in_array(mb_strtolower(trim($r['title'])), $existing, true); @endphp
            <li class="px-5 py-4 text-sm">
                <div class="flex items-start gap-3">
                    @if ($canAccept && ! $dup)<input type="checkbox" name="selected[]" value="{{ $i }}" class="checkbox mt-1" checked>@endif
                    <div class="flex-1 space-y-1.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-navy-900">{{ $r['title'] }}</span>
                            <x-status :value="$r['likelihood']" /><span class="text-2xs text-ink-500">likelihood</span>
                            <x-status :value="$r['magnitude']" /><span class="text-2xs text-ink-500">magnitude</span>
                            @if ($r['fraud'])<span class="badge badge-danger">Fraud</span>@endif
                            @if ($dup)<span class="badge badge-neutral">Already added</span>@endif
                            @if (! empty($r['unverified_figures']))<span class="badge badge-danger">Unverified figures: {{ implode(', ', $r['unverified_figures']) }}</span>@endif
                        </div>
                        <p class="text-ink-700">{{ $r['description'] }}</p>
                        <p class="text-xs text-ink-500"><span class="font-semibold">Basis:</span> {{ $r['basis'] }}</p>
                        <p class="text-xs text-ink-600">{{ $r['level'] === 'assertion' ? collect($r['fsli_codes'])->map(fn ($c) => $fslis[$c] ?? $c)->join(', ').' · '.collect($r['assertions'])->map(fn ($a) => \App\Enums\Assertion::from($a)->label())->join(', ') : 'Financial statement level' }}</p>
                        <p class="text-xs text-ink-600"><span class="font-semibold">Planned response:</span> {{ $r['planned_response'] }}</p>
                        @foreach ($r['citations'] as $c)<p class="text-xs"><span class="font-medium">{{ $c['citation'] ?? '#'.$c['paragraph_id'] }}</span> — <span class="text-ink-500">{{ $c['relevance'] }}</span></p>@endforeach
                        @if ($canAccept && ! $dup)
                            <label class="flex items-center gap-2 text-xs text-ink-700"><input type="checkbox" name="significant[]" value="{{ $i }}" class="checkbox" @checked($r['suggest_significant'])>Record as significant risk (needs partner approval){{ $r['suggest_significant'] ? ' — suggested by the agent' : '' }}</label>
                        @endif
                    </div>
                </div>
            </li>
        @empty
            <li class="px-5 py-4 text-sm text-ink-500">The agent proposed no new risks.</li>
        @endforelse
    </ul>
</form>
