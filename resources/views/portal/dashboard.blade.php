<x-layouts.portal title="Overview">
    <h1 class="text-2xl font-semibold">{{ $client->name }}</h1>
    <p class="mt-1 text-sm text-ink-500">Requests from your audit team and the files you have provided.</p>
    @php $open = $items->filter->isOpen(); @endphp
    <div class="mt-6 grid gap-4 sm:grid-cols-3">
        <x-stat label="Open requests" :value="$open->count()" />
        <x-stat label="Overdue" :value="$items->filter->isOverdue()->count()" />
        <x-stat label="Submitted for review" :value="$items->where('status', \App\Enums\PbcStatus::Submitted)->count()" />
    </div>
    <div class="mt-6 card overflow-x-auto">
        <div class="card-header"><h2 class="card-title">Audits</h2></div>
        <table class="table">
            <thead><tr><th>Engagement</th><th>Period</th><th class="text-right">Open requests</th></tr></thead>
            <tbody>
            @forelse ($engagements as $e)
                <tr class="row-link"><td><a href="{{ route('portal.engagement', $e) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $e->name }}</a></td><td class="tabular-nums">{{ $e->periodLabel() }}</td><td class="text-right">{{ $open->where('engagement_id', $e->id)->count() }}</td></tr>
            @empty
                <tr><td colspan="3"><x-empty title="No audits yet" description="Your audit team will share requests here." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.portal>
