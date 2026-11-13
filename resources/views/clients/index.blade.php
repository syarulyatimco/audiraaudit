<x-layouts.app title="Clients">
    <x-page-header title="Clients" subtitle="Every client passes acceptance & continuance before an engagement can begin.">
        @can('create', \App\Models\Client::class)
            <x-slot:actions><a href="{{ route('clients.create') }}" class="btn-primary">New client</a></x-slot:actions>
        @endcan
    </x-page-header>
    <form method="GET" class="mb-4 flex flex-wrap gap-3">
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search name or registration no." class="input max-w-xs">
        <select name="status" class="input max-w-[14rem]" onchange="this.form.submit()">
            <option value="">All statuses</option>
            @foreach (\App\Enums\ClientStatus::options() as $v => $l)<option value="{{ $v }}" @selected(($filters['status'] ?? '') === $v)>{{ $l }}</option>@endforeach
        </select>
        <button class="btn-secondary">Search</button>
    </form>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Client</th><th>Entity type</th><th>Framework</th><th>FYE</th><th class="text-right">Engagements</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($clients as $c)
                <tr class="row-link">
                    <td><a href="{{ route('clients.show', $c) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $c->name }}</a><div class="font-mono text-2xs text-ink-500">{{ $c->registration_no ?? '—' }}</div></td>
                    <td>{{ $c->entity_type->label() }}</td>
                    <td><span class="badge badge-neutral">{{ $c->reporting_framework->label() }}</span></td>
                    <td class="tabular-nums">{{ $c->fyeLabel() }}</td>
                    <td class="text-right tabular-nums">{{ $c->engagements_count }}</td>
                    <td><x-status :value="$c->status" /></td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty title="No clients found" description="Add a client, then complete the acceptance assessment for partner approval.">
                    @can('create', \App\Models\Client::class)<a href="{{ route('clients.create') }}" class="btn-primary btn-sm">New client</a>@endcan
                </x-empty></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $clients->links() }}</div>
</x-layouts.app>
