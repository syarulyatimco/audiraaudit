<x-layouts.app :title="'Findings · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-fieldwork-nav :engagement="$engagement" />
    @php
        $active = $findings->where('status', '!=', \App\Enums\FindingStatus::Withdrawn);
        $open = $active->where('status', \App\Enums\FindingStatus::Open)->count();
        $sigPending = $active->filter->needsApproval()->count();
    @endphp
    <div class="space-y-6">
        @include('ai.partials.panel', ['agent' => 'findings', 'task' => 'draft_findings', 'title' => 'AI findings drafting',
            'blurb' => 'The Findings agent reads sample exceptions, procedure conclusions and existing findings, and drafts findings with amounts taken from your records. Nothing is recorded until you accept it.', 'show' => $canManage])

        <div class="grid gap-4 sm:grid-cols-3">
            <x-stat label="Open findings" :value="$open" />
            <x-stat label="Misstatements" :value="$active->where('type', \App\Enums\FindingType::Misstatement)->count()" />
            <x-stat label="Significant, not yet approved" :value="$sigPending" />
        </div>

        <div class="card overflow-x-auto">
            <div class="card-header">
                <div class="inline-flex rounded-md border border-navy-100 bg-white p-0.5 text-xs">
                    <a href="{{ route('findings.index', $engagement) }}" class="rounded px-2.5 py-1 {{ ! $type ? 'bg-navy-800 text-white' : 'text-ink-600' }}">All</a>
                    @foreach (\App\Enums\FindingType::options() as $k => $l)
                        <a href="{{ route('findings.index', [$engagement, 'type' => $k]) }}" class="rounded px-2.5 py-1 {{ $type === $k ? 'bg-navy-800 text-white' : 'text-ink-600' }}">{{ $l }}</a>
                    @endforeach
                </div>
                @if ($canManage)<a href="{{ route('findings.create', $engagement) }}" class="btn-primary btn-sm">Record finding</a>@endif
            </div>
            @if ($findings->isEmpty())
                <x-empty title="No findings recorded" description="Record misstatements, control deficiencies and other matters as they arise in testing. Evaluated samples can record their misstatements here directly." />
            @else
                <table class="table">
                    <thead><tr><th>Ref</th><th>Finding</th><th>Type</th><th class="text-right">Amount (RM)</th><th>Correction</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach ($findings as $f)
                        <tr class="row-link {{ $f->status === \App\Enums\FindingStatus::Withdrawn ? 'opacity-60' : '' }}">
                            <td class="font-mono text-xs font-semibold">{{ $f->reference }}</td>
                            <td><a href="{{ route('findings.show', [$engagement, $f]) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $f->title }}</a>
                                <div class="text-xs text-ink-500">{{ $f->fsli?->name }}{{ $f->procedure ? ' · '.$f->procedure->reference : '' }}@if($f->source === 'ai') · <span class="text-teal-700">AI draft</span>@elseif($f->source === 'sample') · from sample @endif
                                    @if ($f->is_significant) · <span class="font-semibold text-red-700">Significant</span>{{ $f->significance_status ? ' ('.$f->significance_status->label().')' : ' (not submitted)' }}@endif</div></td>
                            <td class="text-xs">{{ $f->type->label() }}@if($f->misstatement_class) · {{ $f->misstatement_class->label() }}@endif @if($f->deficiency_severity) · {{ $f->deficiency_severity->label() }}@endif</td>
                            <td class="text-right tabular-nums">{{ $f->amount_sen !== null ? amount($f->amount_sen) : '—' }}</td>
                            <td>@if ($f->correction_status)<x-status :value="$f->correction_status" />@else — @endif</td>
                            <td><x-status :value="$f->status" /></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-layouts.app>
