<x-layouts.app :title="'AI assistant · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    @if ($unavailable)
        <div class="mb-6 rounded-md border border-navy-100 bg-white px-4 py-3 text-sm text-ink-600">AI is not available: {{ implode(' ', $unavailable) }}</div>
    @endif
    <div class="mb-6 grid gap-4 md:grid-cols-2">
        @foreach ($agents as $key => $agent)
            @foreach ($agent->tasks() as $task => $def)
                <div class="card card-body">
                    <div class="text-2xs font-semibold uppercase tracking-wider text-teal-700">{{ $agent->name() }}</div>
                    <div class="mt-1 text-sm font-semibold text-navy-900">{{ $def['label'] }}</div>
                    <p class="mt-1 text-xs text-ink-500">{{ $def['description'] }}</p>
                    <div class="mt-3">@include('ai.partials.start', ['agent' => $key, 'task' => $task, 'label' => 'Run', 'unavailable' => $unavailable])</div>
                </div>
            @endforeach
        @endforeach
    </div>
    <div class="card overflow-x-auto">
        <div class="card-header"><h2 class="card-title">Run log</h2><span class="text-xs text-ink-500">Every prompt, tool call and result is recorded</span></div>
        <table class="table text-sm">
            <thead><tr><th>Started</th><th>Task</th><th>Requested by</th><th>Model</th><th class="text-right">Tokens</th><th class="text-right">Credits</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($runs as $r)
                <tr class="row-link">
                    <td class="whitespace-nowrap text-ink-500">{{ local_time($r->created_at) }}</td>
                    <td><a href="{{ route('ai-runs.show', [$engagement, $r]) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $agents[$r->agent]->tasks()[$r->task]['label'] ?? $r->task }}</a></td>
                    <td>{{ $r->requester->name }}</td>
                    <td class="font-mono text-xs text-ink-500">{{ $r->model ?? '—' }}</td>
                    <td class="text-right tabular-nums">{{ number_format($r->input_tokens + $r->output_tokens) }}</td>
                    <td class="text-right tabular-nums">{{ $r->credits }}</td>
                    <td>@include('ai.partials.run-status', ['run' => $r])</td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty title="No AI runs yet" description="Run a task above. Results are drafts for your review." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.app>
