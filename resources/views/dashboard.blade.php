<x-layouts.app title="Dashboard">
    <x-page-header :eyebrow="$firm->name" title="Good {{ now(config('audira.display_timezone'))->hour < 12 ? 'morning' : (now(config('audira.display_timezone'))->hour < 18 ? 'afternoon' : 'evening') }}, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}" subtitle="Here's where your engagements stand today.">
        <x-slot:actions>
            @can('create', \App\Models\Client::class)
                <a href="{{ route('clients.create') }}" class="btn-secondary">New client</a>
            @endcan
            @can('create', \App\Models\Engagement::class)
                <a href="{{ route('engagements.create') }}" class="btn-primary">New engagement</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Active engagements" :value="$stats['active_engagements']" hint="Planning through reporting" :href="route('engagements.index')" />
        <x-stat label="Accepted clients" :value="$stats['accepted_clients']" :href="route('clients.index', ['status' => 'accepted'])" />
        <x-stat label="Awaiting acceptance" :value="$stats['pending_acceptance']" hint="Client acceptance approvals" :href="route('clients.index', ['status' => 'pending_acceptance'])" />
        <x-stat label="Awaiting your decision" :value="$stats['awaiting_me']" hint="Approvals you can decide" :href="route('approvals.index')" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <div class="card-header">
                <h2 class="card-title">Your engagements</h2>
                <a href="{{ route('engagements.index') }}" class="text-xs font-medium text-teal-700 hover:text-teal-800">View all</a>
            </div>
            @if ($engagements->isEmpty())
                <x-empty title="No engagements yet" description="Engagements you're assigned to will appear here. Clients must pass acceptance before an engagement can be created.">
                    @can('create', \App\Models\Engagement::class)<a href="{{ route('engagements.create') }}" class="btn-primary btn-sm">Create engagement</a>@endcan
                </x-empty>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Engagement</th><th>Period end</th><th>Partner</th><th>Deadline</th><th>Status</th></tr></thead>
                        <tbody>
                        @foreach ($engagements as $e)
                            <tr class="row-link">
                                <td><a href="{{ route('engagements.show', $e) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $e->client->name }}</a><div class="font-mono text-2xs text-ink-500">{{ $e->code }}</div></td>
                                <td class="tabular-nums">{{ $e->period_end->format('j M Y') }}</td>
                                <td>{{ $e->partner()?->name ?? '—' }}</td>
                                <td class="tabular-nums {{ $e->reporting_deadline && $e->reporting_deadline->isPast() ? 'font-semibold text-red-700' : '' }}">{{ $e->reporting_deadline?->format('j M Y') ?? '—' }}</td>
                                <td><x-status :value="$e->status" /></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Awaiting your approval</h2><a href="{{ route('approvals.index') }}" class="text-xs font-medium text-teal-700">Open queue</a></div>
                @forelse ($awaitingMe as $a)
                    <a href="{{ $a->approvable->approvalUrl() }}" class="block border-b border-navy-50 px-5 py-3 last:border-0 hover:bg-navy-50/40">
                        <div class="text-2xs font-semibold uppercase tracking-wide text-amber-700">{{ $a->type->label() }}</div>
                        <div class="text-sm font-medium text-navy-900">{{ $a->approvable->approvalTitle() }}</div>
                        <div class="text-xs text-ink-500">Requested by {{ $a->requester->name }} · {{ $a->requested_at->diffForHumans() }}</div>
                    </a>
                @empty
                    <p class="px-5 py-6 text-sm text-ink-500">Nothing needs your decision right now.</p>
                @endforelse
            </div>

            @if ($activity->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Recent activity</h2><a href="{{ route('firm.activity') }}" class="text-xs font-medium text-teal-700">Audit trail</a></div>
                    <ul class="px-5 py-2">
                        @foreach ($activity as $log)
                            <li class="flex gap-3 py-2.5 text-sm">
                                <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-teal-500"></span>
                                <span><span class="text-ink-700">{{ $log->description }}</span><span class="block text-xs text-ink-400">{{ $log->actor_label }} · {{ $log->created_at->diffForHumans() }}</span></span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
