<x-layouts.app :title="'Audit programme · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-fieldwork-nav :engagement="$engagement" />
    @php $active = $procedures->whereNull('withdrawn_at'); $done = $active->where('status', \App\Enums\ProcedureStatus::Completed)->count(); @endphp
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @include('ai.partials.panel', ['agent' => 'audit_programme', 'task' => 'draft_procedures', 'title' => 'AI procedure drafting',
                'blurb' => 'The Audit Programme agent drafts procedures traced to the risks in your register. Nothing is added until you accept it.', 'show' => $canEditPlanning])

            <div class="card overflow-x-auto">
                <div class="card-header">
                    <div><h2 class="card-title">Procedures</h2><p class="text-xs text-ink-500">{{ $active->count() }} active · {{ $done }} completed · {{ number_format($active->sum('budget_hours'), 1) }} budget hours</p></div>
                    @if ($canEditPlanning)<a href="{{ route('programme.create', $engagement) }}" class="btn-primary btn-sm">New procedure</a>@endif
                </div>
                @if ($active->isEmpty())
                    <x-empty title="No procedures yet" description="Design procedures that respond to each assessed risk, or ask the AI agent for a first draft." />
                @else
                    <table class="table">
                        <thead><tr><th>Ref</th><th>Procedure</th><th>Responds to</th><th>Assigned</th><th>Status</th></tr></thead>
                        <tbody>
                        @foreach ($active as $p)
                            <tr class="row-link">
                                <td class="font-mono text-xs font-semibold">{{ $p->reference }}</td>
                                <td><a href="{{ route('programme.show', [$engagement, $p]) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $p->title }}</a>
                                    <div class="text-xs text-ink-500">{{ $p->nature->label() }}{{ $p->fsli ? ' · '.$p->fsli->name : '' }}@if($p->source === 'ai') · <span class="text-teal-700">AI draft</span>@endif</div></td>
                                <td class="text-xs">{{ $p->risks->pluck('reference')->join(', ') ?: '—' }}</td>
                                <td class="text-xs">{{ $p->assignee?->name ?? '—' }}</td>
                                <td><x-status :value="$p->status" /></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Risk coverage</h2></div>
                <div class="card-body text-sm">
                    @if ($uncovered->isEmpty())
                        <p class="text-emerald-700">Every active risk has at least one responding procedure.</p>
                    @else
                        <p class="text-ink-600">Risks without a responding procedure:</p>
                        <ul class="mt-2 space-y-1">@foreach ($uncovered as $r)<li><a href="{{ route('risks.show', [$engagement, $r]) }}" class="text-navy-900 hover:text-teal-700">{{ $r->reference }} {{ $r->title }}</a>@if($r->is_significant) <span class="badge badge-danger">Significant</span>@endif</li>@endforeach</ul>
                    @endif
                </div>
            </div>
            @include('planning.partials.paper')
        </div>
    </div>
</x-layouts.app>
