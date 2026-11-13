@php
    $out = $run->output ?? [];
    $canAccept = ! $engagement->status->isLocked() && \App\Support\Authorization\Permissions::allowsOnEngagement(auth()->user(), $engagement, 'engagement.manage_programme');
    $existing = $engagement->procedures()->active()->pluck('title')->map(fn ($t) => mb_strtolower(trim($t)))->all();
@endphp
<form method="POST" action="{{ route('ai-runs.accept-procedures', [$engagement, $run]) }}" class="card">
    @csrf
    <div class="card-header"><div><h3 class="card-title">AI-drafted procedures ({{ count($out['procedures'] ?? []) }})</h3><p class="text-xs text-ink-500">{{ $out['summary'] ?? '' }}</p></div>
        @if ($canAccept && ! empty($out['procedures']))<button class="btn-accent btn-sm">Add selected to programme</button>@endif</div>
    @include('ai.partials.guard-note')
    <ul class="divide-y divide-navy-50">
        @forelse ($out['procedures'] ?? [] as $i => $p)
            @php $dup = in_array(mb_strtolower(trim($p['title'])), $existing, true); @endphp
            <li class="flex gap-3 px-5 py-4 text-sm">
                @if ($canAccept && ! $dup)<input type="checkbox" name="selected[]" value="{{ $i }}" class="checkbox mt-1" checked>@endif
                <div class="flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-2"><span class="font-semibold text-navy-900">{{ $p['title'] }}</span><span class="badge badge-neutral">{{ \App\Enums\ProcedureNature::from($p['nature'])->label() }}</span>@if($dup)<span class="badge badge-neutral">Already added</span>@endif</div>
                    <p class="text-xs text-ink-500">Responds to {{ implode(', ', $p['risk_references']) }}{{ $p['fsli_code'] ? ' · '.$p['fsli_code'] : '' }} · {{ collect($p['assertions'])->map(fn ($a) => \App\Enums\Assertion::from($a)->label())->join(', ') }}</p>
                    <p class="whitespace-pre-line text-ink-700">{{ $p['steps'] }}</p>
                    @foreach ($p['citations'] as $c)<p class="text-xs"><span class="font-medium">{{ $c['citation'] ?? '#'.$c['paragraph_id'] }}</span> — <span class="text-ink-500">{{ $c['relevance'] }}</span></p>@endforeach
                </div>
            </li>
        @empty
            <li class="px-5 py-4 text-sm text-ink-500">No procedures proposed.</li>
        @endforelse
    </ul>
</form>
