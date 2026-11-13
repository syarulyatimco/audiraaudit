<x-layouts.app title="Audit trail">
    <x-page-header title="Audit trail" subtitle="Append-only record of every significant action in your firm. Entries cannot be edited or deleted." />
    <form method="GET" class="mb-4 flex flex-wrap gap-3">
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search description or person" class="input max-w-xs">
        <select name="event" class="input max-w-[12rem]" onchange="this.form.submit()">
            <option value="">All areas</option>
            @foreach ($events as $e)<option value="{{ $e }}" @selected(($filters['event'] ?? '') === $e)>{{ ucfirst($e) }}</option>@endforeach
        </select>
        <button class="btn-secondary">Filter</button>
    </form>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th class="w-44">When (MYT)</th><th>Event</th><th>Description</th><th>By</th><th>IP</th></tr></thead>
            <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td class="whitespace-nowrap tabular-nums text-ink-500">{{ local_time($log->created_at, 'j M Y, H:i:s') }}</td>
                    <td><span class="font-mono text-2xs text-navy-700">{{ $log->event }}</span></td>
                    <td class="text-ink-900">{{ $log->description }}</td>
                    <td class="whitespace-nowrap">{{ $log->actor_label }}@if($log->actor_type !== 'user')<span class="badge badge-neutral ml-1">{{ $log->actor_type }}</span>@endif</td>
                    <td class="font-mono text-2xs text-ink-500">{{ $log->ip_address }}</td>
                </tr>
            @empty
                <tr><td colspan="5"><x-empty title="No matching entries" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $logs->links() }}</div>
</x-layouts.app>
