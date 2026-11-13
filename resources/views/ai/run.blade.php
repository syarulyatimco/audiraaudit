@php $out = $run->output ?? []; $def = $agent->tasks()[$run->task]; @endphp
<x-layouts.app :title="$def['label']">
    <x-engagement-nav :engagement="$engagement" />
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div><a href="{{ route('ai-runs.index', $engagement) }}" class="text-sm text-ink-500 hover:text-navy-900">← AI assistant</a>
            <h2 class="mt-1 text-xl font-semibold">{{ $def['label'] }}</h2>
            <p class="text-sm text-ink-500">{{ $agent->name() }} · requested by {{ $run->requester->name }} {{ local_time($run->created_at) }} · {{ $run->model ?? 'model pending' }}</p></div>
        @include('ai.partials.run-status', ['run' => $run])
    </div>

    <div class="mb-6 rounded-md border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-900">AI output is a draft prepared for the audit team. It has not changed any records. Review each item before accepting it.</div>

    @if ($run->error)
        <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $run->error }}</div>
    @endif
    @if (! empty($out['violations']))
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <div class="font-semibold">Guardrails removed or flagged {{ count($out['violations']) }} item(s):</div>
            <ul class="mt-1 list-inside list-disc text-xs">@foreach (array_slice($out['violations'], 0, 20) as $v)<li>{{ $v }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($run->isUsable())
        @include('ai.partials.results', ['run' => $run, 'engagement' => $engagement])
    @endif

    <details class="card mt-6">
        <summary class="card-header cursor-pointer"><span class="card-title">Execution log ({{ $run->steps->count() }} steps · {{ number_format($run->input_tokens) }} in / {{ number_format($run->output_tokens) }} out tokens · {{ $run->credits }} credits)</span></summary>
        <ol class="divide-y divide-navy-50 text-xs">
            @foreach ($run->steps as $s)
                <li class="px-5 py-2" x-data="{ open: false }">
                    <button type="button" class="flex w-full items-center gap-3 text-left" @click="open = !open">
                        <span class="w-6 tabular-nums text-ink-400">{{ $s->seq }}</span>
                        <span class="badge {{ in_array($s->type, ['error', 'tool_denied']) ? 'badge-danger' : ($s->type === 'guardrail' ? 'badge-warn' : 'badge-neutral') }}">{{ str_replace('_', ' ', $s->type) }}</span>
                        <span class="font-mono text-navy-800">{{ $s->name }}</span>
                        <span class="ml-auto text-ink-400">{{ $s->input_tokens + $s->output_tokens ? number_format($s->input_tokens + $s->output_tokens).' tok · ' : '' }}{{ $s->duration_ms ? $s->duration_ms.' ms · ' : '' }}{{ local_time($s->created_at, 'H:i:s') }}</span>
                    </button>
                    <pre x-show="open" x-cloak class="mt-2 max-h-96 overflow-auto rounded bg-navy-950 p-3 font-mono text-2xs text-navy-100">{{ json_encode($s->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </li>
            @endforeach
        </ol>
    </details>
</x-layouts.app>
