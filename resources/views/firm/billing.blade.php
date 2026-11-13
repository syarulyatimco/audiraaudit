<x-layouts.app title="Subscription">
    <x-page-header title="Subscription" subtitle="Per-user pricing. Client portal accounts are always free." />
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <div class="card-header"><h2 class="card-title">Current plan</h2>@if($estimate['subscription'])<x-status :value="$estimate['subscription']->status" />@endif</div>
            <div class="card-body">
                @if ($estimate['plan'])
                    <div class="flex items-baseline justify-between">
                        <div>
                            <div class="text-xl font-semibold text-navy-900">{{ $estimate['plan']->name }}</div>
                            <div class="text-sm text-ink-500">{{ money_label($estimate['price_per_user_sen']) }} per user / month · minimum {{ $estimate['min_paid_users'] }} paid users</div>
                        </div>
                        <div class="text-right">
                            <div class="text-2xs font-semibold uppercase tracking-wider text-ink-500">Estimated monthly</div>
                            <div class="text-2xl font-semibold tabular-nums text-navy-900">{{ money_label($estimate['monthly_total_sen']) }}</div>
                        </div>
                    </div>
                    <dl class="mt-6">
                        <div class="dl-row"><dt>Active staff users</dt><dd class="tabular-nums">{{ $estimate['active_users'] }}</dd></div>
                        <div class="dl-row"><dt>Billable users (min. {{ $estimate['min_paid_users'] }})</dt><dd class="tabular-nums">{{ $estimate['billable_users'] }}</dd></div>
                        <div class="dl-row"><dt>User limit</dt><dd>{{ $estimate['max_users'] ?? 'Unlimited' }}</dd></div>
                        <div class="dl-row"><dt>Current period</dt><dd>{{ local_time($estimate['subscription']->current_period_start, 'j M Y') }} – {{ local_time($estimate['subscription']->current_period_end, 'j M Y') }}</dd></div>
                        @if ($firm->status === \App\Enums\FirmStatus::Trial)
                            <div class="dl-row"><dt>Trial ends</dt><dd>{{ local_time($firm->trial_ends_at, 'j M Y') }}</dd></div>
                        @endif
                    </dl>
                    <p class="mt-4 text-xs text-ink-500">Invoices are issued monthly in arrears of each period for the billable users at that time. To change plan, contact AUDIRA support.</p>
                @else
                    <x-empty title="No active subscription" description="Contact AUDIRA support to reactivate your subscription." />
                @endif
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Available plans</h2></div>
            <ul class="divide-y divide-navy-50">
                @foreach ($plans as $plan)
                    <li class="flex items-center justify-between px-5 py-3">
                        <div><div class="text-sm font-medium text-navy-900">{{ $plan->name }}</div><div class="text-xs text-ink-500">{{ $plan->description }}</div></div>
                        <div class="text-right text-sm font-semibold tabular-nums">{{ money_label($plan->price_per_user_sen) }}<span class="block text-2xs font-normal text-ink-500">/user/mo</span></div>
                    </li>
                @endforeach
            </ul>
        </div>
        <div class="card lg:col-span-3 overflow-x-auto">
            <div class="card-header"><h2 class="card-title">Invoices</h2></div>
            @if ($invoices->isEmpty())
                <x-empty title="No invoices yet" description="Your first invoice is issued at the end of your first paid period." />
            @else
                <table class="table text-sm">
                    <thead><tr><th>Number</th><th>Period</th><th>Issued</th><th>Due</th><th class="text-right">Total (RM)</th><th>Status</th></tr></thead>
                    <tbody>@foreach ($invoices as $inv)
                        <tr class="row-link"><td><a class="link font-mono text-xs" href="{{ route('firm.invoices.show', $inv) }}">{{ $inv->number }}</a></td>
                            <td>{{ $inv->period_start->format('j M Y') }} – {{ $inv->period_end->format('j M Y') }}</td><td>{{ local_time($inv->issued_at, 'j M Y') }}</td>
                            <td class="{{ $inv->isOverdue() ? 'font-semibold text-red-700' : '' }}">{{ $inv->due_on->format('j M Y') }}</td>
                            <td class="text-right tabular-nums">{{ amount($inv->total_sen, false) }}</td><td><x-status :value="$inv->isOverdue() ? 'past_due' : $inv->status" /></td></tr>
                    @endforeach</tbody>
                </table>
            @endif
        </div>
    </div>
</x-layouts.app>
