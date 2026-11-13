<x-layouts.app title="Plans & pricing">
    <x-page-header eyebrow="Superadmin" title="Plans & pricing" subtitle="Price changes apply to new or renewed subscriptions; existing periods keep their snapshot price.">
        <x-slot:actions><a href="{{ route('admin.plans.create') }}" class="btn-primary">New plan</a></x-slot:actions>
    </x-page-header>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Plan</th><th class="text-right">Price / user / month</th><th class="text-right">Min paid users</th><th class="text-right">Max users</th><th class="text-right">AI credits / user</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @foreach ($plans as $p)
                <tr>
                    <td><div class="font-medium text-navy-900">{{ $p->name }}</div><div class="font-mono text-2xs text-ink-500">{{ $p->code }}</div></td>
                    <td class="text-right font-semibold tabular-nums">{{ money_label($p->price_per_user_sen, $p->currency) }}</td>
                    <td class="text-right tabular-nums">{{ $p->min_paid_users }}</td>
                    <td class="text-right tabular-nums">{{ $p->max_users ?? '∞' }}</td>
                    <td class="text-right tabular-nums">{{ number_format($p->ai_credits_per_user) }}</td>
                    <td>@if($p->is_active)<span class="badge badge-success badge-dot">Active</span>@else<span class="badge badge-neutral badge-dot">Inactive</span>@endif</td>
                    <td class="text-right"><a href="{{ route('admin.plans.edit', $p) }}" class="btn-secondary btn-sm">Edit</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</x-layouts.app>
