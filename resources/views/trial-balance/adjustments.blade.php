<x-layouts.app :title="'Adjustments · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-tb-nav :engagement="$engagement" />
    <div class="mb-4 flex items-center justify-between">
        <p class="text-sm text-ink-500">Posted adjustments flow into the adjusted trial balance and lead schedules. The preparer cannot post their own entry.</p>
        @if ($canPrepare)<a href="{{ route('adjustments.create', $engagement) }}" class="btn-primary">New adjustment</a>@endif
    </div>
    <div class="card overflow-x-auto">
        <table class="table text-sm">
            <thead><tr><th>No.</th><th>Description</th><th class="text-right">Amount</th><th>Prepared</th><th>Posted</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($adjustments as $a)
                <tr class="row-link {{ $a->status === \App\Enums\AdjustmentStatus::Voided ? 'opacity-50' : '' }}">
                    <td class="font-mono text-xs font-semibold"><a href="{{ route('adjustments.show', [$engagement, $a]) }}" class="text-navy-900 hover:text-teal-700">{{ $a->number }}</a></td>
                    <td class="max-w-md truncate">{{ $a->description }}</td>
                    <td class="text-right tabular-nums">{{ amount($a->totalDebitSen(), false) }}</td>
                    <td class="text-xs text-ink-600">{{ $a->preparer->name }}</td>
                    <td class="text-xs text-ink-600">{{ $a->poster?->name ?? '—' }}</td>
                    <td>@php $tone = ['draft' => 'warn', 'posted' => 'success', 'voided' => 'neutral'][$a->status->value]; @endphp<span class="badge badge-dot badge-{{ $tone }}">{{ $a->status->label() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty title="No adjustments" description="Record proposed audit adjustments and reclassifications here." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.app>
