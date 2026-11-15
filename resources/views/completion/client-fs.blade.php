<x-layouts.app :title="'Management FS · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-completion-nav :engagement="$engagement" />
    @php $rm = fn ($v) => $v === null ? '' : amount($v); @endphp

    @if (! $fs)
        <div class="card">
            <x-empty title="Management has not started its financial statements" description="Management prepares the financial statements in the client portal (Financial statements on their engagement page) and submits them to you. The audit team can also set up and edit the working draft with them; only management submits versions and records the directors' approval.">
                @if ($portalUsers === 0)<a href="{{ route('clients.show', $engagement->client) }}" class="btn-secondary btn-sm">Invite a client portal user</a>@endif
            </x-empty>
            @if ($canEdit && ! $engagement->status->isLocked())
                <form method="POST" action="{{ route('client-fs.start', $engagement) }}" class="flex flex-wrap items-center justify-center gap-2 border-t border-navy-100/70 px-5 py-4 text-sm">
                    @csrf
                    <label for="fs-template" class="text-ink-600">Set up the working draft with layout</label>
                    <select id="fs-template" name="template" class="input max-w-xs !py-1.5">@foreach ($templates as $k => $l)<option value="{{ $k }}" @selected($k === $suggested)>{{ $l }}</option>@endforeach</select>
                    <button class="btn-primary btn-sm">Set up draft</button>
                </form>
                @error('template')<p class="error px-5 pb-4 text-center">{{ $message }}</p>@enderror
                @error('fs')<p class="error px-5 pb-4 text-center">{{ $message }}</p>@enderror
            @endif
        </div>
    @else
        @php $tone = ['draft' => 'neutral', 'submitted' => 'info', 'changes_requested' => 'warn', 'accepted' => 'success'][$fs->status]; @endphp
        <div class="card card-body mb-6 flex flex-wrap items-center justify-between gap-3 text-sm">
            <div>
                <div class="font-semibold text-navy-900">Financial statements prepared by management</div>
                <div class="text-ink-500">{{ $engagement->client->name }} · {{ $engagement->periodLabel() }} · layout: {{ config('fs_templates.'.$fs->template.'.label') }}</div>
            </div>
            <div class="flex items-center gap-2">
                <span class="badge badge-dot badge-{{ $tone }}">{{ ['draft' => 'Being prepared', 'submitted' => 'Awaiting review', 'changes_requested' => 'Changes requested', 'accepted' => 'Accepted for the report'][$fs->status] }}</span>
                @if ($shown && $pdf)<a href="{{ route('client-fs.pdf', [$engagement, 'version' => $shown->uuid]) }}" class="btn-secondary btn-sm">PDF</a>@endif
            </div>
        </div>
        @foreach (['review', 'comment', 'reopen', 'reason', 'tieout', 'fs', 'file', 'statement', 'notes'] as $k)@error($k)<p class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror @endforeach

        @php $view = in_array(request('tab'), ['review', 'draft', 'history'], true) ? request('tab') : ($shown ? 'review' : 'draft'); @endphp
        <div x-data="{ view: '{{ $view }}' }">
            <div class="mb-4 inline-flex rounded-md border border-navy-100 bg-white p-0.5 text-sm">
                <button type="button" @click="view = 'review'" :class="view === 'review' ? 'bg-navy-800 text-white' : 'text-ink-600 hover:text-navy-900'" class="rounded px-3 py-1.5 font-medium">Submitted versions ({{ $versions->count() }})</button>
                <button type="button" @click="view = 'draft'" :class="view === 'draft' ? 'bg-navy-800 text-white' : 'text-ink-600 hover:text-navy-900'" class="rounded px-3 py-1.5 font-medium">Working draft</button>
                <button type="button" @click="view = 'history'" :class="view === 'history' ? 'bg-navy-800 text-white' : 'text-ink-600 hover:text-navy-900'" class="rounded px-3 py-1.5 font-medium">Change history</button>
            </div>

            <div x-show="view === 'review'" x-cloak>
            @if (! $shown)
                <div class="card"><x-empty title="Nothing submitted yet" description="Management is preparing the statements. You will see each version here once they submit it." /></div>
            @else
                <div class="grid gap-6 lg:grid-cols-3">
                    <div class="space-y-6 lg:col-span-2">
                        @foreach (\App\Models\ClientFinancialStatement::STATEMENTS as $k)
                            @php $st = $content['statements'][$k] ?? ['label' => $k, 'rows' => []]; @endphp
                            <div class="card overflow-x-auto">
                                <div class="card-header"><h2 class="card-title">{{ $st['label'] }}</h2><span class="text-xs text-ink-500">Version {{ $shown->version }}</span></div>
                                <table class="table">
                                    <thead><tr><th></th><th class="w-14">Note</th><th class="text-right">Current year</th><th class="text-right">Prior year</th></tr></thead>
                                    <tbody>
                                    @foreach ($st['rows'] as $r)
                                        <tr class="{{ $r['kind'] === 'heading' ? 'bg-navy-50/40' : '' }}"><td class="{{ $r['kind'] !== 'line' ? 'font-semibold text-navy-900' : '' }}">{{ $r['caption'] }}@if($k === 'sofp')<span class="ml-1 text-2xs text-ink-400">{{ $r['side'] === 'asset' ? 'A' : 'E&L' }}</span>@endif</td>
                                            <td class="text-xs">{{ $r['note'] }}</td><td class="text-right tabular-nums">{{ $rm($r['cy']) }}</td><td class="text-right tabular-nums">{{ $rm($r['py']) }}</td></tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endforeach

                        <div class="card overflow-x-auto">
                            <div class="card-header">
                                <div><h2 class="card-title">Tie-out to audited figures</h2><p class="text-xs text-ink-500">Each line compared with the adjusted TB of the FSLIs it maps to. Suggested matches are by caption; enter FSLI codes to change them.</p></div>
                                <span class="text-xs {{ $tie['differences'] || $tie['unmapped'] ? 'text-amber-700' : 'text-emerald-700' }}">{{ $tie['differences'] }} difference(s) · {{ $tie['unmapped'] }} not tied out</span>
                            </div>
                            <form method="POST" action="{{ route('client-fs.tieout', $engagement) }}">
                                @csrf @method('PUT')
                                <table class="table">
                                    <thead><tr><th>Line</th><th class="w-40">FSLI codes</th><th class="text-right">Management</th><th class="text-right">Audited</th><th class="text-right">Difference</th></tr></thead>
                                    <tbody>
                                    @foreach ($tie['lines'] as $l)
                                        <tr>
                                            <td><span class="text-2xs uppercase text-ink-400">{{ $l['statement'] }}</span> {{ $l['caption'] }}</td>
                                            <td>@if ($canReview)<input name="map[{{ $l['id'] }}]" value="{{ implode(', ', $l['codes']) }}" class="input !py-1 text-xs {{ $l['guessed'] ? 'border-dashed' : '' }}" placeholder="e.g. TR" title="{{ $l['guessed'] ? 'Suggested by caption' : '' }}">@else<span class="text-xs">{{ implode(', ', $l['codes']) ?: '—' }}</span>@endif</td>
                                            <td class="text-right tabular-nums">{{ amount($l['client'][0]) }}</td>
                                            <td class="text-right tabular-nums">{{ $l['codes'] ? amount($l['audited'][0]) : '' }}</td>
                                            <td class="text-right tabular-nums font-medium {{ ['agreed' => 'text-emerald-700', 'difference' => 'text-red-700', 'unmapped' => 'text-amber-700', 'empty' => 'text-ink-400'][$l['status']] }}">{{ ['agreed' => '✓', 'difference' => amount($l['difference'][0], false), 'unmapped' => 'not tied', 'empty' => '—'][$l['status'] ] }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                                @if ($canReview)<div class="flex justify-end border-t border-navy-100/70 px-5 py-3"><button class="btn-secondary btn-sm">Save tie-out</button></div>@endif
                            </form>
                        </div>

                        <div class="card">
                            <div class="card-header"><h2 class="card-title">Notes</h2><span class="text-xs text-ink-500">{{ count($content['notes'] ?? []) }}</span></div>
                            <div class="divide-y divide-navy-50">
                                @forelse ($content['notes'] ?? [] as $i => $n)
                                    <details class="px-5 py-3"><summary class="cursor-pointer text-sm font-medium text-navy-900">{{ $i + 1 }}. {{ $n['title'] }}</summary><p class="mt-2 whitespace-pre-line text-sm text-ink-700">{{ $n['body'] ?: '—' }}</p></details>
                                @empty
                                    <p class="px-5 py-4 text-sm text-ink-500">No notes.</p>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <div class="card">
                            <div class="card-header"><h2 class="card-title">Checks</h2></div>
                            <dl class="card-body text-sm">
                                <div class="dl-row"><dt>Total assets</dt><dd>{{ amount($checks['sofp_assets'][0], false) }}</dd></div>
                                <div class="dl-row"><dt>Total equity &amp; liabilities</dt><dd>{{ amount($checks['sofp_el'][0], false) }}</dd></div>
                                <div class="dl-row"><dt>SOFP balances</dt><dd class="{{ $checks['sofp_balanced'] ? 'text-emerald-700' : 'text-red-700' }}">{{ $checks['sofp_balanced'] ? 'Yes' : 'No' }}</dd></div>
                                <div class="dl-row"><dt>Approved by directors</dt><dd>{{ $shown->directors_approved ? $shown->approved_by_names.' · '.$shown->approved_on->format('j M Y') : 'No — draft' }}</dd></div>
                                <div class="dl-row"><dt>Submitted by</dt><dd>{{ $shown->submitter->name }} · {{ local_time($shown->submitted_at, 'j M Y') }}</dd></div>
                                <div class="dl-row"><dt>Content SHA-256</dt><dd class="font-mono text-2xs" title="{{ $shown->content_sha256 }}">{{ substr($shown->content_sha256, 0, 16) }}…</dd></div>
                            </dl>
                        </div>

                        @if ($canReview && $shown->id === $versions->first()->id && $shown->review_status === 'received')
                            <form method="POST" action="{{ route('client-fs.review', [$engagement, $shown]) }}" class="card" x-data="{ d: '{{ $shown->directors_approved ? 'accept' : 'request_changes' }}' }">
                                @csrf
                                <div class="card-header"><h2 class="card-title">Review version {{ $shown->version }}</h2></div>
                                <div class="card-body space-y-3 text-sm">
                                    <label class="flex items-center gap-2"><input type="radio" name="decision" value="request_changes" x-model="d"> Ask management for changes</label>
                                    <label class="flex items-center gap-2 {{ $shown->directors_approved ? '' : 'opacity-50' }}"><input type="radio" name="decision" value="accept" x-model="d" @disabled(! $shown->directors_approved)> Accept for the audit report</label>
                                    @unless ($shown->directors_approved)<p class="hint">Only a version approved by the directors can be accepted.</p>@endunless
                                    <textarea name="comment" rows="4" class="input" :placeholder="d === 'accept' ? 'Explain any differences or untied lines (required if there are any)' : 'What management needs to change'"></textarea>
                                    <button class="btn-primary w-full">Record review</button>
                                </div>
                            </form>
                        @elseif ($canReview && $fs->status === 'accepted')
                            <form method="POST" action="{{ route('client-fs.reopen', $engagement) }}" class="card" onsubmit="return confirm('Re-open the accepted statements for management to revise?')">
                                @csrf
                                <div class="card-header"><h2 class="card-title">Re-open</h2></div>
                                <div class="card-body space-y-3 text-sm">
                                    <p class="text-ink-600">Use when management must revise accepted statements before the report is signed (e.g. an adjusting event).</p>
                                    <textarea name="reason" rows="3" class="input" required placeholder="Reason"></textarea>
                                    <button class="btn-danger btn-sm">Re-open statements</button>
                                </div>
                            </form>
                        @endif

                        <div class="card">
                            <div class="card-header"><h2 class="card-title">Versions</h2></div>
                            <ul class="divide-y divide-navy-50 text-sm">
                                @foreach ($versions as $v)
                                    <li class="px-5 py-3 {{ $v->id === $shown->id ? 'bg-teal-50/40' : '' }}">
                                        <div class="flex items-center justify-between gap-2">
                                            <a href="{{ route('client-fs.show', [$engagement, 'version' => $v->uuid]) }}" class="font-medium text-navy-900 hover:text-teal-700">Version {{ $v->version }}</a>
                                            <span class="badge {{ ['received' => 'badge-info', 'changes_requested' => 'badge-warn', 'accepted' => 'badge-success', 'superseded' => 'badge-neutral'][$v->review_status] }}">{{ str_replace('_', ' ', $v->review_status) }}</span>
                                        </div>
                                        <div class="text-xs text-ink-500">{{ local_time($v->submitted_at, 'j M Y') }} · {{ $v->directors_approved ? 'directors approved' : 'draft' }}@if($v->reviewer) · reviewed by {{ $v->reviewer->name }}@endif</div>
                                        @if ($v->review_comment)<p class="mt-1 whitespace-pre-line text-xs text-ink-700">{{ $v->review_comment }}</p>@endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            @endif
            </div>

            <div x-show="view === 'draft'" x-cloak x-data="{ tab: 'sofp' }">
                <div class="mb-4 rounded-md border border-teal-200 bg-teal-50/60 px-4 py-3 text-sm text-teal-900">
                    This is the live draft management sees in the portal. @if ($canEdit && ! $locked)You can add rows, change amounts and edit notes; each save is recorded in the change history as an audit-team change.@elseif ($fs->status === 'accepted')The accepted statements are frozen — re-open them to make changes.@else You can view the draft; editing needs the partner, manager or senior role on this engagement.@endif Only management submits a version and records the directors' approval.
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="inline-flex flex-wrap rounded-md border border-navy-100 bg-white p-0.5 text-sm">
                        @foreach (\App\Models\ClientFinancialStatement::STATEMENTS as $k)
                            <button type="button" @click="tab = '{{ $k }}'" :class="tab === '{{ $k }}' ? 'bg-navy-800 text-white' : 'text-ink-600 hover:text-navy-900'" class="rounded px-3 py-1.5 font-medium">{{ \Illuminate\Support\Str::limit($fs->label($k), 34) }}</button>
                        @endforeach
                        <button type="button" @click="tab = 'notes'" :class="tab === 'notes' ? 'bg-navy-800 text-white' : 'text-ink-600 hover:text-navy-900'" class="rounded px-3 py-1.5 font-medium">Notes</button>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('client-fs.export', $engagement) }}" class="btn-secondary btn-sm">Download .xlsx</a>
                        @if ($canEdit && ! $locked)<button type="button" class="btn-secondary btn-sm" onclick="document.getElementById('fs-upload').click()">Upload .xlsx</button>@endif
                        @if ($pdf)<a href="{{ route('client-fs.draft-pdf', $engagement) }}" class="btn-secondary btn-sm">Draft PDF</a>@endif
                    </div>
                </div>
                @if ($canEdit && ! $locked)
                    <form method="POST" action="{{ route('client-fs.import', $engagement) }}" enctype="multipart/form-data" class="hidden">@csrf<input type="file" id="fs-upload" name="file" accept=".xlsx" onchange="this.form.submit()"></form>
                @endif
                <x-fs-editor :fs="$fs" :statement-url="route('client-fs.statement', [$engagement, '__KEY__'])" :notes-url="route('client-fs.notes', $engagement)" :locked="$locked || ! $canEdit" />
            </div>

            <div x-show="view === 'history'" x-cloak>
                <x-fs-history :history="$history" />
            </div>
        </div>
    @endif
</x-layouts.app>
