{{-- Live status badge for an AI run; reloads the page when the run finishes. $run, $engagement --}}
<span x-data="{ status: @js($run->status), label: @js($run->statusLabel()), active: @js($run->isActive()),
        init() { if (!this.active) return; const t = setInterval(async () => {
            const r = await fetch('{{ route('ai-runs.show', [$engagement, $run]) }}', { headers: { Accept: 'application/json' } });
            if (!r.ok) return; const d = await r.json(); this.label = d.label;
            if (d.finished) { clearInterval(t); window.location.reload(); } }, 3000); } }"
      class="badge badge-dot badge-{{ $run->statusTone() }}" x-text="label">{{ $run->statusLabel() }}</span>
