@php
    $out = $run->output ?? [];
    $doc = $engagement->documents()->where('uuid', $run->input['subject'] ?? '')->first();
    $done = $doc && $doc->extractions()->where('ai_run_id', $run->id)->exists();
@endphp
<div class="rounded-md border border-teal-100 bg-teal-50/40 px-3 py-2 text-xs">
    @include('ai.partials.guard-note')
    <div class="flex flex-wrap items-center gap-2"><span class="badge badge-teal">{{ str_replace('_', ' ', $out['document_type'] ?? '') }}</span><x-status :value="$out['confidence'] ?? 'low'" /><span class="text-ink-500">confidence</span></div>
    <p class="mt-1 text-ink-700">{{ $out['summary'] ?? '' }}</p>
    <div class="mt-1 text-ink-600">@foreach (($out['fields'] ?? []) as $k => $v)@if($v !== null)<span class="mr-3"><span class="font-semibold">{{ $k }}</span> {{ $v }}</span>@endif @endforeach</div>
    @if (! $done && \App\Support\Authorization\Permissions::allowsOnEngagement(auth()->user(), $engagement, 'engagement.manage_pbc'))
        <form method="POST" action="{{ route('ai-runs.accept-extraction', [$engagement, $run]) }}" class="mt-2 flex items-center gap-3">@csrf
            @if (! empty($out['suggested_pbc_reference']))<label class="flex items-center gap-1"><input type="checkbox" name="link" value="1" class="checkbox" checked>Link to {{ $out['suggested_pbc_reference'] }}</label>@endif
            <button class="btn-secondary btn-sm">Record extraction</button></form>
    @elseif ($done)
        <p class="mt-1 text-emerald-700">Extraction recorded.</p>
    @endif
</div>
