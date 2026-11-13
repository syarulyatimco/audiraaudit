<x-layouts.app :title="'Risk assessment · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-planning-nav :engagement="$engagement" />

    @php
        $active = $risks->whereNull('withdrawn_at');
        $withdrawn = $risks->whereNotNull('withdrawn_at');
    @endphp
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @include('ai.partials.panel', ['agent' => 'risk_assessment', 'task' => 'suggest_risks', 'title' => 'AI risk identification',
                'blurb' => 'The Risk Assessment agent proposes risks of material misstatement from the trial balance analytics and your entity understanding, with cited library paragraphs. Nothing is added until you accept it.',
                'show' => $canEditPlanning])

            <div class="card overflow-x-auto">
                <div class="card-header">
                    <div><h2 class="card-title">Risk register</h2><p class="text-xs text-ink-500">{{ $active->count() }} active · {{ $active->where('is_significant', true)->count() }} significant · {{ $active->where('is_fraud_risk', true)->count() }} fraud</p></div>
                    @if ($canEditPlanning)
                        <div class="flex gap-2">
                            @if ($risks->whereNotNull('presumed_key')->count() < 2)
                                <form method="POST" action="{{ route('risks.presumed', $engagement) }}">@csrf<button class="btn-secondary btn-sm">Add presumed fraud risks</button></form>
                            @endif
                            <a href="{{ route('risks.create', $engagement) }}" class="btn-primary btn-sm">New risk</a>
                        </div>
                    @endif
                </div>
                @if ($active->isEmpty())
                    <x-empty title="No risks recorded" description="Start with the presumed fraud risks, then add the risks identified from your understanding of the entity and analytical procedures." />
                @else
                    <table class="table">
                        <thead><tr><th>Ref</th><th>Risk</th><th>FSLI · assertions</th><th>Inherent risk</th><th>Significant</th></tr></thead>
                        <tbody>
                        @foreach ($active as $r)
                            <tr class="row-link">
                                <td class="font-mono text-xs font-semibold">{{ $r->reference }}</td>
                                <td><a href="{{ route('risks.show', [$engagement, $r]) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $r->title }}</a>
                                    <div class="mt-0.5 flex flex-wrap gap-1">
                                        @if ($r->is_fraud_risk)<span class="badge badge-danger">Fraud</span>@endif
                                        @if ($r->presumed_key)<span class="badge badge-neutral">Presumed</span>@endif
                                        @if ($r->source === 'ai')<span class="badge badge-teal">AI suggested</span>@endif
                                    </div></td>
                                <td class="text-xs">{{ $r->level === \App\Enums\RiskLevel::FinancialStatement ? 'Financial statement level' : $r->fslis->pluck('name')->join(', ') }}
                                    @if ($r->assertions)<div class="text-ink-500">{{ collect($r->assertions)->map(fn ($a) => \App\Enums\Assertion::from($a)->label())->join(', ') }}</div>@endif</td>
                                <td><x-status :value="$r->inherentRisk()" /><div class="text-2xs text-ink-500">L {{ $r->likelihood->label() }} · M {{ $r->magnitude->label() }}</div></td>
                                <td>@if ($r->is_significant)<x-status :value="$r->significance_status ?? 'not_submitted'" />@else<span class="text-xs text-ink-400">No</span>@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            @if ($withdrawn->isNotEmpty())
                <details class="card">
                    <summary class="card-header cursor-pointer"><span class="card-title">Withdrawn or rebutted ({{ $withdrawn->count() }})</span></summary>
                    <ul class="divide-y divide-navy-50 text-sm">
                        @foreach ($withdrawn as $r)
                            <li class="px-5 py-3"><a href="{{ route('risks.show', [$engagement, $r]) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $r->reference }} {{ $r->title }}</a>
                                <p class="text-xs text-ink-500">{{ local_time($r->withdrawn_at, 'j M Y') }} — {{ $r->withdrawn_reason }}</p></li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Coverage check</h2></div>
                <div class="card-body text-sm">
                    @if (! $materiality)
                        <p class="text-ink-500">Approve materiality to see FSLIs at or above performance materiality that have no assertion-level risk linked.</p>
                    @elseif ($gaps->isEmpty())
                        <p class="text-emerald-700">Every FSLI at or above performance materiality (RM {{ amount($materiality->pm_sen) }}) has at least one risk linked.</p>
                    @else
                        <p class="text-ink-600">At or above performance materiality (RM {{ amount($materiality->pm_sen) }}) with no risk linked — consider whether risks exist, and document your conclusion:</p>
                        <ul class="mt-2 space-y-1">@foreach ($gaps as $g)<li class="flex justify-between gap-3"><span>{{ $g['fsli']->name }}</span><span class="tabular-nums text-ink-500">{{ amount($g['amount']) }}</span></li>@endforeach</ul>
                    @endif
                </div>
            </div>
            @include('planning.partials.paper')
        </div>
    </div>
</x-layouts.app>
