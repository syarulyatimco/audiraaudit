<x-layouts.app :title="'Support · '.$firm->name">
    <x-page-header :title="$firm->name" :subtitle="'Read-only support view until '.local_time($grant->expires_at).' — granted by '.$grant->granter->name.': '.$grant->reason" />
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card overflow-x-auto">
                <div class="card-header"><h2 class="card-title">Engagements</h2><span class="text-xs text-ink-500">@foreach ($statusCounts as $s => $n){{ \Illuminate\Support\Str::headline($s) }} {{ $n }}@if(! $loop->last) · @endif @endforeach</span></div>
                <table class="table text-sm"><thead><tr><th>Code</th><th>Status</th><th>Framework</th><th>Period end</th><th>Deadline</th></tr></thead>
                    <tbody>@foreach ($engagements as $e)<tr><td class="font-mono text-xs">{{ $e->code }}</td><td><x-status :value="$e->status" /></td><td>{{ $e->reporting_framework->label() }}</td><td>{{ $e->period_end?->format('j M Y') }}</td><td>{{ $e->reporting_deadline?->format('j M Y') ?? '—' }}</td></tr>@endforeach</tbody></table>
            </div>
            <div class="card overflow-x-auto">
                <div class="card-header"><h2 class="card-title">Recent audit-trail events</h2></div>
                <table class="table text-sm"><tbody>@foreach ($activity as $a)<tr><td class="font-mono text-xs">{{ $a->event }}</td><td>{{ $a->actor_label }}</td><td class="text-xs text-ink-500">{{ local_time($a->created_at) }}</td></tr>@endforeach</tbody></table>
            </div>
        </div>
        <div class="card overflow-x-auto">
            <div class="card-header"><h2 class="card-title">Users</h2></div>
            <ul class="divide-y divide-navy-50 text-sm">@foreach ($users as $u)<li class="px-5 py-2"><div class="flex justify-between"><span>{{ $u->name }}</span><x-status :value="$u->status" /></div>
                <div class="text-xs text-ink-500">{{ $u->type->value === 'client' ? 'Client portal' : ($u->firm_role?->label() ?? '') }}{{ $u->is_firm_admin ? ' · firm admin' : '' }} · MFA {{ $u->mfa_enabled_at ? 'on' : 'off' }}</div></li>@endforeach</ul>
        </div>
    </div>
</x-layouts.app>
