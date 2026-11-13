<x-layouts.app :title="'Audit report · '.$engagement->code">
    @unless ($print)
        <x-engagement-nav :engagement="$engagement" />
        <x-completion-nav :engagement="$engagement" />
    @endunless
    @php
        $os = $report->opinion_status;
        $ss = $report->signoff_status;
        $signed = $report->isSigned();
        $editable = $canPrepare && ! $signed && $os !== \App\Enums\SignificanceStatus::Pending && $ss !== \App\Enums\SignificanceStatus::Pending;
        $completionApproved = $engagement->completion?->completion_status === \App\Enums\SignificanceStatus::Approved;
        $byType = fn ($t) => $report->approvals->filter(fn ($a) => $a->type->value === $t);
        $pendingFor = fn ($t) => $pendingApproval && $pendingApproval->type->value === $t ? $pendingApproval : null;
    @endphp
    @if ($print)
        <article class="mx-auto max-w-3xl whitespace-pre-line bg-white p-10 font-serif text-[15px] leading-relaxed text-ink-900">{{ $report->body }}</article>
        @if ($signed)<p class="mx-auto max-w-3xl px-10 text-xs text-ink-500">Signed by {{ $report->signer?->name }} on {{ local_time($report->signed_at) }} · SHA-256 {{ $report->body_sha256 }}</p>@endif
    @else
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($signed)
                <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">Signed by {{ $report->signer?->name }} on {{ local_time($report->signed_at) }}. The report, the financial statements snapshot and the engagement are locked. <span class="font-mono text-2xs">SHA-256 {{ $report->body_sha256 }}</span></div>
            @elseif (! $completionApproved)
                <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Completion has not been approved yet. You can draft the report, but the opinion can only be submitted after completion is approved. <a href="{{ route('completion.index', $engagement) }}" class="link">Go to completion</a></div>
            @endif

            <form method="POST" action="{{ route('reporting.save', $engagement) }}" class="card" x-data="{ op: @js(old('opinion', $report->opinion?->value)), gc: @js((bool) old('going_concern_uncertainty', $report->going_concern_uncertainty)) }">
                @csrf @method('PUT')
                <div class="card-header"><h2 class="card-title">Opinion and report</h2>@if($report->opinion)<span class="badge badge-neutral">{{ $report->opinion->label() }}</span>@endif</div>
                <div class="card-body space-y-4 text-sm">
                    @if ($editable)
                        <p class="rounded-md bg-navy-50 px-3 py-2 text-xs text-ink-600">The opinion is the engagement partner's decision. The team records the proposed opinion and its basis; the partner decides it, then signs the report — both with re-authentication. AI does not propose or draft opinions.</p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-select name="opinion" label="Proposed opinion" :options="\App\Enums\AuditOpinion::options()" :value="$report->opinion" placeholder="Choose…" x-model="op" />
                            <x-field name="report_date" label="Report date" type="date" :value="$report->report_date?->toDateString()" />
                        </div>
                        <div x-show="op && op !== 'unmodified'"><x-field name="basis_for_modification" label="Basis for modification" type="textarea" rows="3" :value="$report->basis_for_modification" /></div>
                        <label class="flex items-center gap-2"><input type="hidden" name="going_concern_uncertainty" value="0"><input type="checkbox" name="going_concern_uncertainty" value="1" class="checkbox" x-model="gc"> Material uncertainty related to going concern</label>
                        <div x-show="gc"><x-field name="going_concern_paragraph" label="Material uncertainty related to going concern — paragraph" type="textarea" rows="3" :value="$report->going_concern_paragraph" /></div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-field name="emphasis_of_matter" label="Emphasis of matter (optional)" type="textarea" rows="3" :value="$report->emphasis_of_matter" />
                            <x-field name="other_matter" label="Other matter (optional)" type="textarea" rows="3" :value="$report->other_matter" />
                        </div>
                        <x-field name="body" label="Report text" type="textarea" rows="18" :value="$report->body" hint="Generate from your firm's template, then review every paragraph. Unfilled placeholders block sign-off." />
                        @if ($report->opinion && ! $hasTemplate)<p class="text-xs text-amber-800">Your firm has no approved template for this opinion; generating gives a headings-only skeleton. Firm admins and partners maintain templates under Report templates.</p>@endif
                    @else
                        <dl>
                            <div class="dl-row"><dt>Opinion</dt><dd>{{ $report->opinion?->label() ?? '—' }}</dd></div>
                            <div class="dl-row"><dt>Report date</dt><dd>{{ $report->report_date?->format('j M Y') ?? '—' }}</dd></div>
                            @if ($report->basis_for_modification)<div class="dl-row"><dt>Basis for modification</dt><dd class="whitespace-pre-line font-normal">{{ $report->basis_for_modification }}</dd></div>@endif
                        </dl>
                        <article class="whitespace-pre-line rounded-md border border-navy-100 bg-white p-6 font-serif text-[15px] leading-relaxed">{{ $report->body ?: 'No report text yet.' }}</article>
                    @endif
                </div>
                @if ($editable)
                    <div class="flex justify-end gap-2 border-t border-navy-100/70 px-5 py-3">
                        <button name="action" value="generate" class="btn-secondary" onclick="return !this.form.body.value || confirm('Replace the report text with the template?')">Generate text from template</button>
                        <button name="action" value="save" class="btn-primary">Save</button>
                    </div>
                @endif
            </form>
            @if ($report->body)<a href="{{ route('reporting.report', [$engagement, 'print' => 1]) }}" target="_blank" class="btn-secondary">Print view</a>@endif
        </div>
        <div class="space-y-6">
            @if ($report->exists)
                @include('completion.partials.stage', ['title' => '1 · Audit opinion', 'status' => $os, 'gates' => $opinionGates, 'route' => route('reporting.submit', [$engagement, 'opinion']),
                    'canSubmit' => $canPrepare && ! $signed, 'approval' => $pendingFor('audit_opinion'), 'history' => $byType('audit_opinion'), 'approveLabel' => 'Approve this opinion'])
                @include('completion.partials.stage', ['title' => '2 · Report sign-off', 'status' => $ss, 'gates' => $signoffGates, 'route' => route('reporting.submit', [$engagement, 'signoff']),
                    'canSubmit' => $canPrepare && ! $signed, 'approval' => $pendingFor('report_signoff'), 'history' => $byType('report_signoff'), 'approveLabel' => 'Sign the audit report'])
            @endif
            <a href="{{ route('reporting.statements', $engagement) }}" class="card block px-5 py-4 text-sm hover:border-teal-300"><div class="font-medium text-navy-900">Financial statements →</div><p class="text-xs text-ink-500">Primary statements from the adjusted trial balance; snapshotted at sign-off.</p></a>
        </div>
    </div>
    @endif
</x-layouts.app>
