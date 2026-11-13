{{-- Guardrail notice for a run: $run, $engagement --}}
@if (! empty($run->output['violations']))
    <div class="border-b border-amber-200 bg-amber-50 px-5 py-2.5 text-xs text-amber-900">
        <span class="font-semibold">Guardrails removed or flagged {{ count($run->output['violations']) }} item(s).</span>
        {{ \Illuminate\Support\Str::limit($run->output['violations'][0], 160) }}
        <a href="{{ route('ai-runs.show', [$engagement, $run]) }}" class="font-medium underline">See all</a>
    </div>
@endif
