<x-layouts.app title="Platform overview">
    <x-page-header eyebrow="Superadmin" title="Platform overview" subtitle="Firm, subscription and seat metrics. Superadmins have no access to firms' client or audit data." />
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Audit firms" :value="$stats['firms']" hint="{{ $stats['active'] }} active · {{ $stats['trial'] }} trial · {{ $stats['suspended'] }} suspended" :href="route('admin.firms.index')" />
        <x-stat label="Paid staff seats" :value="$stats['paid_users']" hint="{{ $stats['client_users'] }} free client portal users" />
        <x-stat label="Estimated MRR" :value="money_label($stats['mrr_sen'])" hint="Active firms, current seat counts" />
        <x-stat label="Trial pipeline" :value="money_label($stats['trial_pipeline_sen'])" hint="If all trials convert" />
    </div>
    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <div class="card-header"><h2 class="card-title">Newest firms</h2><a href="{{ route('admin.firms.index') }}" class="text-xs font-medium text-teal-700">All firms</a></div>
            <table class="table">
                <thead><tr><th>Firm</th><th>Plan</th><th>Registered</th><th>Status</th></tr></thead>
                <tbody>
                @forelse ($recentFirms as $f)
                    <tr class="row-link"><td><a href="{{ route('admin.firms.show', $f) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $f->name }}</a></td><td>{{ $f->currentSubscription?->plan?->name ?? '—' }}</td><td class="tabular-nums">{{ local_time($f->created_at, 'j M Y') }}</td><td><x-status :value="$f->status" /></td></tr>
                @empty
                    <tr><td colspan="4"><x-empty title="No firms yet" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="space-y-6">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Billing</h2><a href="{{ route('admin.invoices.index') }}" class="text-xs font-medium text-teal-700">Invoices</a></div>
            <dl class="card-body text-sm">
                <div class="dl-row"><dt>Outstanding</dt><dd class="tabular-nums">RM {{ amount($analytics['outstanding_sen'], false) }}</dd></div>
                <div class="dl-row"><dt>Overdue invoices</dt><dd class="tabular-nums {{ $analytics['overdue'] ? 'text-red-700' : '' }}">{{ $analytics['overdue'] }}</dd></div>
                <div class="dl-row"><dt>Collected this month</dt><dd class="tabular-nums">RM {{ amount($analytics['collected_month_sen'], false) }}</dd></div>
                <div class="dl-row"><dt>Past-due subscriptions</dt><dd class="tabular-nums">{{ $analytics['past_due_firms'] }}</dd></div>
            </dl>
        </div>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Usage</h2>@if($analytics['support_grants'])<a href="{{ route('admin.support.index') }}" class="badge badge-warn">{{ $analytics['support_grants'] }} support grant(s)</a>@endif</div>
            <dl class="card-body text-sm">
                @forelse ($analytics['engagements'] as $st => $n)<div class="dl-row"><dt>Engagements · {{ \Illuminate\Support\Str::headline($st) }}</dt><dd class="tabular-nums">{{ $n }}</dd></div>@empty<p class="text-ink-500">No engagements yet.</p>@endforelse
                <div class="dl-row"><dt>Reports signed this month</dt><dd class="tabular-nums">{{ $analytics['signed_month'] }}</dd></div>
                @foreach ($analytics['ai_runs'] as $agent => $n)<div class="dl-row"><dt>AI runs · {{ \Illuminate\Support\Str::headline($agent) }}</dt><dd class="tabular-nums">{{ $n }}</dd></div>@endforeach
            </dl>
        </div>
        <div class="card">
            <div class="card-header"><h2 class="card-title">AI platform</h2>@if($aiConfigured)<span class="badge badge-success badge-dot">{{ ucfirst($aiProvider) }} configured</span>@else<span class="badge badge-warn badge-dot">No provider configured</span>@endif</div>
            <div class="card-body text-sm text-ink-600">{{ $aiEnabledFirms }} firm(s) have opted in. Credits used this month:</div>
            <ul class="divide-y divide-navy-50">@forelse ($aiUsage as $u)<li class="flex justify-between px-5 py-2 text-sm"><span>{{ $u->name }}</span><span class="tabular-nums">{{ number_format($u->credits) }} cr · {{ number_format($u->tokens) }} tok</span></li>@empty<li class="px-5 py-3 text-sm text-ink-500">No usage yet.</li>@endforelse</ul>
        </div>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Plans</h2><a href="{{ route('admin.plans.index') }}" class="text-xs font-medium text-teal-700">Manage</a></div>
            <ul class="divide-y divide-navy-50">
                @foreach ($plans as $p)
                    <li class="flex items-center justify-between px-5 py-3 text-sm"><div><div class="font-medium text-navy-900">{{ $p->name }} @unless($p->is_active)<span class="badge badge-neutral">Inactive</span>@endunless</div><div class="text-xs text-ink-500">{{ $p->live_subscriptions }} live subscriptions</div></div><div class="font-semibold tabular-nums">{{ money_label($p->price_per_user_sen) }}</div></li>
                @endforeach
            </ul>
        </div>
        </div>
    </div>
</x-layouts.app>
