@props(['engagement'])
@php
    $tabs = [
        ['engagements.show', 'Overview', 'engagements.show'],
        ['entity-understanding.show', 'Understanding the entity', 'entity-understanding.*'],
        ['trial-balance.index', 'Trial balance', ['trial-balance.*', 'lead-schedules.*', 'adjustments.*']],
        ['strategy.show', 'Planning', ['strategy.*', 'materiality.*', 'risks.*']],
        ['programme.index', 'Fieldwork', ['programme.*', 'sampling.*', 'findings.*']],
        ['completion.index', 'Completion', ['completion.*', 'reporting.*']],
        ['working-papers.index', 'Working papers', 'working-papers.*'],
        ['client-requests.index', 'Client requests', ['client-requests.*']],
        ['documents.index', 'Documents', 'documents.*'],
        ['ai-runs.index', 'AI assistant', 'ai-runs.*'],
    ];
@endphp
<div class="mb-6">
    <a href="{{ route('clients.show', $engagement->client) }}" class="text-2xs font-semibold uppercase tracking-widest text-teal-700 hover:text-teal-800">{{ $engagement->client->name }}</a>
    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
        <h1 class="text-2xl font-semibold">{{ $engagement->name }}</h1>
        <x-status :value="$engagement->status" />
        @if ($engagement->status->isLocked())<span class="badge badge-neutral">Read-only</span>@endif
    </div>
    <p class="mt-1 text-sm text-ink-500">{{ $engagement->code }} · {{ $engagement->reporting_framework->label() }} · {{ $engagement->periodLabel() }}</p>
    <nav class="mt-5 flex gap-1 overflow-x-auto border-b border-navy-100">
        @foreach ($tabs as [$route, $label, $pattern])
            <a href="{{ route($route, $engagement) }}" class="-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium {{ request()->routeIs($pattern) ? 'border-teal-600 text-navy-900' : 'border-transparent text-ink-500 hover:text-navy-900' }}">{{ $label }}</a>
        @endforeach
    </nav>
</div>
