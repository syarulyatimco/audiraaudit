<x-layouts.app title="Engagements">
    <x-page-header title="Engagements" :subtitle="auth()->user()->hasPermission('engagements.view_all') ? 'All engagements in your firm.' : 'Engagements you are assigned to.'">
        @can('create', \App\Models\Engagement::class)
            <x-slot:actions><a href="{{ route('engagements.create') }}" class="btn-primary">New engagement</a></x-slot:actions>
        @endcan
    </x-page-header>
    <form method="GET" class="mb-4 flex flex-wrap gap-3">
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search client, code or name" class="input max-w-xs">
        <select name="status" class="input max-w-[12rem]" onchange="this.form.submit()">
            <option value="">All stages</option>
            @foreach (\App\Enums\EngagementStatus::options() as $v => $l)<option value="{{ $v }}" @selected(($filters['status'] ?? '') === $v)>{{ $l }}</option>@endforeach
        </select>
        <button class="btn-secondary">Search</button>
    </form>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Client / engagement</th><th>Type</th><th>Period</th><th>Partner</th><th>Team</th><th>Deadline</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($engagements as $e)
                <tr class="row-link">
                    <td><a href="{{ route('engagements.show', $e) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $e->client->name }}</a><div class="text-xs text-ink-500"><span class="font-mono">{{ $e->code }}</span> · {{ $e->name }}</div></td>
                    <td class="text-ink-600">{{ $e->engagement_type->label() }}</td>
                    <td class="whitespace-nowrap tabular-nums">{{ $e->periodLabel() }}</td>
                    <td>{{ $e->partner()?->name ?? '—' }}</td>
                    <td>
                        <div class="flex -space-x-1.5">
                            @foreach ($e->members->take(4) as $m)
                                <span title="{{ $m->user->name }} — {{ $m->role->label() }}" class="flex h-6 w-6 items-center justify-center rounded-full border-2 border-white bg-navy-100 text-2xs font-semibold text-navy-800">{{ $m->user->initials() }}</span>
                            @endforeach
                            @if ($e->members->count() > 4)<span class="flex h-6 w-6 items-center justify-center rounded-full border-2 border-white bg-navy-50 text-2xs text-ink-600">+{{ $e->members->count() - 4 }}</span>@endif
                        </div>
                    </td>
                    <td class="whitespace-nowrap tabular-nums">{{ $e->reporting_deadline?->format('j M Y') ?? '—' }}</td>
                    <td><x-status :value="$e->status" /></td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty title="No engagements found" description="Engagements you're staffed on appear here." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $engagements->links() }}</div>
</x-layouts.app>
