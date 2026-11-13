@php $out = $run->output ?? []; @endphp
<div class="card">
    <div class="card-header"><h3 class="card-title">Health digest</h3></div>
    @include('ai.partials.guard-note')
    <div class="card-body space-y-3 text-sm">
        <p class="text-ink-700">{{ $out['summary'] ?? '' }}</p>
        <ol class="list-decimal space-y-1 pl-5">@foreach ($out['priorities'] ?? [] as $p)<li>{{ $p['action'] }}@if($p['alert_kind']) <span class="badge badge-neutral">{{ \Illuminate\Support\Str::headline($p['alert_kind']) }}</span>@endif</li>@endforeach</ol>
    </div>
</div>
