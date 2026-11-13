<x-layouts.app :title="$firm->name">
    <x-page-header eyebrow="Superadmin · Firm" :title="$firm->name" subtitle="Registered {{ local_time($firm->created_at, 'j M Y') }}">
        <x-slot:actions><x-status :value="$firm->status" /></x-slot:actions>
    </x-page-header>
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Subscription</h2></div>
                <dl class="card-body">
                    <div class="dl-row"><dt>Plan</dt><dd>{{ $estimate['plan']?->name ?? '—' }}</dd></div>
                    <div class="dl-row"><dt>Price snapshot</dt><dd>{{ money_label($estimate['price_per_user_sen']) }} / user / month</dd></div>
                    <div class="dl-row"><dt>Active / billable users</dt><dd class="tabular-nums">{{ $estimate['active_users'] }} / {{ $estimate['billable_users'] }}</dd></div>
                    <div class="dl-row"><dt>Estimated monthly</dt><dd class="tabular-nums">{{ money_label($estimate['monthly_total_sen']) }}</dd></div>
                </dl>
                <form method="POST" action="{{ route('admin.firms.plan', $firm) }}" class="flex items-end gap-3 border-t border-navy-50 px-5 py-4">
                    @csrf @method('PUT')
                    <x-select class="flex-1" name="plan_id" label="Change plan" :options="$plans->mapWithKeys(fn ($p) => [$p->id => $p->name.' — '.money_label($p->price_per_user_sen)])->all()" :value="$estimate['plan']?->id" />
                    <button class="btn-primary">Apply</button>
                </form>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Staff accounts</h2></div>
                <table class="table">
                    <thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Last sign-in</th></tr></thead>
                    <tbody>
                    @foreach ($staff as $u)
                        <tr><td><div class="font-medium text-navy-900">{{ $u->name }}</div><div class="text-xs text-ink-500">{{ $u->email }}</div></td><td>{{ $u->firm_role?->label() }}{{ $u->is_firm_admin ? ' · Admin' : '' }}</td><td><x-status :value="$u->status" /></td><td class="text-ink-500">{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Subscription history</h2></div>
                <table class="table">
                    <thead><tr><th>Plan</th><th>Price</th><th>Period</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach ($subscriptions as $s)
                        <tr><td>{{ $s->plan->name }}</td><td class="tabular-nums">{{ money_label($s->price_per_user_sen) }}</td><td class="tabular-nums">{{ local_time($s->current_period_start, 'j M Y') }} – {{ local_time($s->current_period_end, 'j M Y') }}</td><td><x-status :value="$s->status" /></td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Firm details</h2></div>
                <dl class="card-body">
                    <div class="dl-row"><dt>Registration no.</dt><dd>{{ $firm->registration_no ?? '—' }}</dd></div>
                    <div class="dl-row"><dt>Audit firm no.</dt><dd>{{ $firm->audit_firm_no ?? '—' }}</dd></div>
                    <div class="dl-row"><dt>Email</dt><dd>{{ $firm->email ?? '—' }}</dd></div>
                    <div class="dl-row"><dt>Trial ends</dt><dd>{{ local_time($firm->trial_ends_at, 'j M Y') }}</dd></div>
                </dl>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Invoices</h2>
                    @if ($subscriptions->first())<form method="POST" action="{{ route('admin.firms.invoices.issue', $firm) }}">@csrf<button class="btn-secondary btn-sm">Issue for current period</button></form>@endif</div>
                <ul class="divide-y divide-navy-50 text-sm">
                    @forelse ($invoices as $inv)
                        <li class="flex justify-between px-5 py-2"><a class="link font-mono text-xs" href="{{ route('admin.invoices.show', $inv) }}">{{ $inv->number }}</a><span class="tabular-nums">RM {{ amount($inv->total_sen, false) }}</span><x-status :value="$inv->isOverdue() ? 'past_due' : $inv->status" /></li>
                    @empty<li class="px-5 py-3 text-ink-500">None.</li>@endforelse
                </ul>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Access</h2></div>
                <div class="card-body">
                    @if ($firm->isSuspended())
                        <p class="mb-3 text-sm text-ink-600">Suspended: {{ $firm->suspended_reason }}</p>
                        <form method="POST" action="{{ route('admin.firms.reactivate', $firm) }}" class="space-y-3">
                            @csrf
                            <x-select name="status" label="Restore as" :options="['active' => 'Active (paid)', 'trial' => 'Trial']" value="active" />
                            <button class="btn-accent w-full">Restore access</button>
                        </form>
                    @else
                        @if ($firm->status === \App\Enums\FirmStatus::Trial)
                            <form method="POST" action="{{ route('admin.firms.reactivate', $firm) }}" class="mb-4">@csrf<input type="hidden" name="status" value="active"><button class="btn-secondary w-full">Mark as paid (active)</button></form>
                        @endif
                        <form method="POST" action="{{ route('admin.firms.suspend', $firm) }}" class="space-y-3" onsubmit="return confirm('Suspend this firm? All its users lose access immediately.')">
                            @csrf
                            <x-field name="reason" label="Suspension reason" required />
                            <button class="btn-danger w-full">Suspend firm</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
