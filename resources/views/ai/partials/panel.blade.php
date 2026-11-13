{{-- Compact AI panel for a page: $engagement, $aiRun (latest run for $task or null), $aiUnavailable, $agent (default accounting_tb), $task, $title, $blurb, $show --}}
@php $agent ??= 'accounting_tb'; @endphp
@if ($show)
<div class="card">
    <div class="card-header">
        <div class="min-w-0 flex-1"><h3 class="card-title"><span class="mr-1 text-teal-600">◆</span>{{ $title }}</h3><p class="text-xs text-ink-500">{{ $blurb }}</p></div>
        <div class="flex max-w-sm items-center gap-3">
            @if ($aiRun)
                <a href="{{ route('ai-runs.show', [$engagement, $aiRun]) }}" class="text-xs text-ink-500 hover:text-navy-900">Last run {{ $aiRun->created_at->diffForHumans() }}</a>
                @include('ai.partials.run-status', ['run' => $aiRun])
            @endif
            @if (! $aiRun || ! $aiRun->isActive())
                @include('ai.partials.start', ['agent' => $agent, 'subject' => $subject ?? null, 'task' => $task, 'label' => $aiRun ? 'Run again' : 'Run', 'unavailable' => $aiUnavailable])
            @endif
        </div>
    </div>
    @if ($aiRun && $aiRun->error)<p class="px-5 py-3 text-sm text-red-700">{{ $aiRun->error }}</p>@endif
</div>
@if ($aiRun && $aiRun->isUsable())
    <div class="mt-4">@include('ai.partials.results', ['run' => $aiRun])</div>
@endif
@endif
