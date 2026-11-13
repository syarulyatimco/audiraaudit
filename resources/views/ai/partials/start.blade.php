{{-- Start button for an AI task. $engagement, $agent ('accounting_tb'), $task, $label, $unavailable (list) --}}
@if ($unavailable)
    <p class="max-w-xs text-right text-xs text-ink-500">{{ implode(' ', $unavailable) }}</p>
@else
    <form method="POST" action="{{ route('ai-runs.store', $engagement) }}">
        @csrf
        <input type="hidden" name="agent" value="{{ $agent }}"><input type="hidden" name="task" value="{{ $task }}">@if (! empty($subject))<input type="hidden" name="subject" value="{{ $subject }}">@endif
        <button class="btn-accent btn-sm">{{ $label }}</button>
    </form>
@endif
