<x-layouts.app title="Audit firms">
    <x-page-header eyebrow="Superadmin" title="Audit firms" />
    <form method="GET" class="mb-4 flex flex-wrap gap-3">
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search firm name" class="input max-w-xs">
        <select name="status" class="input max-w-[12rem]" onchange="this.form.submit()"><option value="">All statuses</option>@foreach (\App\Enums\FirmStatus::options() as $v => $l)<option value="{{ $v }}" @selected(($filters['status'] ?? '') === $v)>{{ $l }}</option>@endforeach</select>
        <button class="btn-secondary">Search</button>
    </form>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Firm</th><th>Plan</th><th class="text-right">Active staff</th><th>Trial ends</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($firms as $f)
                <tr class="row-link">
                    <td><a href="{{ route('admin.firms.show', $f) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $f->name }}</a><div class="text-xs text-ink-500">{{ $f->audit_firm_no ?? $f->registration_no }}</div></td>
                    <td>{{ $f->currentSubscription?->plan?->name ?? '—' }}</td>
                    <td class="text-right tabular-nums">{{ $f->active_staff_count }}</td>
                    <td class="tabular-nums">{{ $f->status === \App\Enums\FirmStatus::Trial ? local_time($f->trial_ends_at, 'j M Y') : '—' }}</td>
                    <td><x-status :value="$f->status" /></td>
                </tr>
            @empty
                <tr><td colspan="5"><x-empty title="No firms found" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $firms->links() }}</div>
</x-layouts.app>
