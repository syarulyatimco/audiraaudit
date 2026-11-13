<x-layouts.app :title="'Audit strategy · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-planning-nav :engagement="$engagement" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <form method="POST" action="{{ route('strategy.update', $engagement) }}" class="card">
                @csrf @method('PUT')
                <div class="card-header">
                    <div><h2 class="card-title">{{ $wp?->reference ?? 'A1' }} · Overall audit strategy</h2>
                        <p class="mt-0.5 text-xs text-ink-500">Scope, timing and direction of the audit. Cite authoritative paragraphs on the working paper rather than paraphrasing the standard.</p></div>
                    @if ($strategy?->editor)<span class="text-2xs text-ink-400">Last edited by {{ $strategy->editor->name }} {{ local_time($strategy->updated_at) }}</span>@endif
                </div>
                @if ($wp && $wp->status !== \App\Enums\WorkingPaperStatus::Draft && $canEditPlanning)
                    <p class="mx-5 mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">Signed off. Saving changes invalidates the sign-offs.</p>
                @endif
                <div class="card-body space-y-5">
                    <div class="grid gap-4 sm:grid-cols-4">
                        @foreach (['planning_meeting_on' => 'Planning meeting', 'fieldwork_start_on' => 'Fieldwork starts', 'fieldwork_end_on' => 'Fieldwork ends', 'report_target_on' => 'Target report date'] as $k => $l)
                            @if ($canEditPlanning)
                                <x-field :name="$k" :label="$l" type="date" :value="$strategy?->{$k}?->toDateString()" />
                            @else
                                <div><div class="label">{{ $l }}</div><div class="text-sm">{{ $strategy?->{$k}?->format('j M Y') ?? '—' }}</div></div>
                            @endif
                        @endforeach
                    </div>
                    <div>
                        <span class="label">Planned approach</span>
                        <div class="flex flex-wrap gap-4 text-sm">
                            @foreach (['substantive' => 'Substantive (no reliance on controls)', 'combined' => 'Combined (tests of controls and substantive procedures)'] as $k => $l)
                                <label class="flex items-center gap-2"><input type="radio" name="controls_approach" value="{{ $k }}" class="text-teal-600 focus:ring-teal-500" @checked(old('controls_approach', $strategy?->controls_approach ?? 'substantive') === $k) @disabled(! $canEditPlanning)>{{ $l }}</label>
                            @endforeach
                        </div>
                    </div>
                    @foreach ($sections as $key => [$title, $guide])
                        <div>
                            <label class="label" for="s-{{ $key }}">{{ $title }}@if (in_array($key, $required))<span class="text-red-500"> *</span>@endif</label>
                            <p class="mb-1.5 text-xs text-ink-500">{{ $guide }}</p>
                            @if ($canEditPlanning)
                                <textarea id="s-{{ $key }}" name="sections[{{ $key }}]" rows="4" class="input text-sm">{{ old('sections.'.$key, $strategy?->sections[$key] ?? '') }}</textarea>
                            @else
                                <div class="whitespace-pre-line rounded-md bg-navy-50/50 px-3 py-2 text-sm text-ink-700">{{ $strategy?->sections[$key] ?? '—' }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if ($canEditPlanning)
                    <div class="flex justify-end border-t border-navy-100/70 px-5 py-3"><button class="btn-primary">Save strategy</button></div>
                @endif
            </form>
        </div>
        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Planning summary</h2></div>
                <dl class="px-5 py-2">
                    <div class="dl-row"><dt>Overall materiality</dt><dd>@if ($materiality)RM {{ amount($materiality->om_sen) }}@else<a class="text-teal-700" href="{{ route('materiality.index', $engagement) }}">Not approved</a>@endif</dd></div>
                    <div class="dl-row"><dt>Performance materiality</dt><dd>{{ $materiality ? 'RM '.amount($materiality->pm_sen) : '—' }}</dd></div>
                    <div class="dl-row"><dt>Significant risks</dt><dd>{{ $significant->count() }} ({{ $significant->filter->needsApproval()->count() }} awaiting approval)</dd></div>
                </dl>
                @if ($significant->isNotEmpty())
                    <ul class="border-t border-navy-50 px-5 py-3 text-sm">
                        @foreach ($significant as $r)<li class="flex justify-between gap-2 py-1"><a href="{{ route('risks.show', [$engagement, $r]) }}" class="text-navy-900 hover:text-teal-700">{{ $r->reference }} {{ $r->title }}</a><x-status :value="$r->significance_status ?? 'not_submitted'" /></li>@endforeach
                    </ul>
                @endif
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Engagement team</h2></div>
                <ul class="px-5 py-2 text-sm">@foreach ($team as $m)<li class="flex justify-between py-1"><span>{{ $m->user->name }}</span><span class="text-xs text-ink-500">{{ $m->role->label() }}</span></li>@endforeach</ul>
            </div>
            @include('planning.partials.paper')
        </div>
    </div>
</x-layouts.app>
