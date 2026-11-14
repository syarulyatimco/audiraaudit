<x-layouts.app :title="'Programmes · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-fieldwork-nav :engagement="$engagement" />
    @php $locked = $engagement->status->isLocked(); @endphp

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if (! $engagement->methodologyLibrary)
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Choose the audit methodology</h2></div>
                    <div class="card-body space-y-3 text-sm text-ink-600">
                        <p>Pick the library for this engagement. AUDIRA sends each library programme to where it belongs: planning programmes to the engagement, controls and substantive programmes to each FSLI with mapped accounts.</p>
                        @if ($mappedCount === 0)
                            <p class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">No FSLI has mapped accounts yet, so only the planning programmes will be created now. Import and map the trial balance, then use “Route new FSLIs”.</p>
                        @endif
                        @if ($canManage && ! $locked)
                            <form method="POST" action="{{ route('programmes.apply', $engagement) }}" class="flex flex-wrap items-end gap-3">
                                @csrf
                                <div class="min-w-[16rem] flex-1">
                                    <label class="label" for="library">Methodology library</label>
                                    <select id="library" name="library" class="input" required>
                                        @foreach ($libraries as $l)<option value="{{ $l->uuid }}">{{ $l->name }}{{ $l->isPlatform() ? ' (AUDIRA template)' : ' (firm)' }}</option>@endforeach
                                    </select>
                                    @error('library')<p class="error">{{ $message }}</p>@enderror
                                </div>
                                <button class="btn-primary">Apply to engagement</button>
                            </form>
                        @else
                            <p class="text-xs text-ink-500">A partner, manager or senior on the engagement chooses the methodology.</p>
                        @endif
                    </div>
                </div>
            @endif

            @foreach ($stages as $stage)
                @php $list = $sheets->get($stage->value, collect()); @endphp
                <div class="card overflow-x-auto">
                    <div class="card-header">
                        <div><h2 class="card-title">{{ $stage->label() }}</h2><p class="text-xs text-ink-500">{{ $list->count() }} programme(s)</p></div>
                    </div>
                    @if ($list->isEmpty())
                        <p class="px-5 py-4 text-sm text-ink-500">No {{ strtolower($stage->label()) }} programmes yet.</p>
                    @else
                        <table class="table">
                            <thead><tr><th>Ref</th><th>Programme</th><th>FSLI</th><th class="text-right">Steps</th><th class="text-right">Done</th><th class="text-right">Reviewed</th></tr></thead>
                            <tbody>
                            @foreach ($list as $s)
                                <tr class="row-link">
                                    <td class="whitespace-nowrap font-mono text-xs font-semibold">{{ $s->reference }}</td>
                                    <td><a href="{{ route('programmes.show', [$engagement, $s]) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $s->title }}</a></td>
                                    <td class="text-xs">{{ $s->fsli ? $s->fsli->code.' · '.$s->fsli->name : 'Engagement' }}</td>
                                    <td class="text-right tabular-nums">{{ $s->rows_count }}</td>
                                    <td class="text-right tabular-nums {{ $s->rows_count && $s->done_count === $s->rows_count ? 'text-emerald-700' : '' }}">{{ $s->done_count }}</td>
                                    <td class="text-right tabular-nums {{ $s->rows_count && $s->reviewed_count === $s->rows_count ? 'text-emerald-700' : '' }}">{{ $s->reviewed_count }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Methodology</h2></div>
                <div class="card-body space-y-3 text-sm">
                    @if ($engagement->methodologyLibrary)
                        <dl>
                            <div class="dl-row"><dt>Library</dt><dd>{{ $engagement->methodologyLibrary->name }}</dd></div>
                            <div class="dl-row"><dt>Applied</dt><dd>{{ local_time($engagement->methodology_applied_at) }}</dd></div>
                            <div class="dl-row"><dt>Steps</dt><dd>{{ $status['rows'] }}</dd></div>
                            <div class="dl-row"><dt>Not yet done</dt><dd>{{ $status['not_done'] }}</dd></div>
                            <div class="dl-row"><dt>Awaiting review</dt><dd>{{ $status['not_reviewed'] }}</dd></div>
                        </dl>
                        @if ($canManage && ! $locked)
                            <form method="POST" action="{{ route('programmes.refresh', $engagement) }}">@csrf<button class="btn-secondary btn-sm w-full">Route new FSLIs</button></form>
                            <p class="hint">Adds programmes for FSLIs mapped since the library was applied. Existing sheets are never changed.</p>
                            @unless ($hasSigned)
                                <details class="text-xs">
                                    <summary class="cursor-pointer text-ink-500 hover:text-navy-900">Switch library</summary>
                                    <form method="POST" action="{{ route('programmes.apply', $engagement) }}" class="mt-2 space-y-2" onsubmit="return confirm('Switching replaces every programme sheet on this engagement. Continue?')">
                                        @csrf
                                        <select name="library" class="input">@foreach ($libraries as $l)<option value="{{ $l->uuid }}" @selected($l->id === $engagement->methodology_library_id)>{{ $l->name }}</option>@endforeach</select>
                                        <button class="btn-danger btn-sm w-full">Replace programmes</button>
                                    </form>
                                </details>
                            @endunless
                        @endif
                    @else
                        <p class="text-ink-500">No library chosen yet.</p>
                    @endif
                    <a href="{{ route('methodology.index') }}" class="block text-xs text-teal-700 hover:underline">Browse methodology libraries →</a>
                </div>
            </div>

            @if ($canManage && ! $locked)
                <div class="card">
                    <div class="card-header"><h2 class="card-title">New programme</h2></div>
                    <form method="POST" action="{{ route('programmes.store', $engagement) }}" class="card-body space-y-3">
                        @csrf
                        <div><label class="label" for="stage">Stage</label>
                            <select id="stage" name="stage" class="input">@foreach ($stages as $s)<option value="{{ $s->value }}" @selected(old('stage') === $s->value)>{{ $s->label() }}</option>@endforeach</select>
                            @error('stage')<p class="error">{{ $message }}</p>@enderror</div>
                        <div><label class="label" for="engagement_fsli_id">FSLI</label>
                            <select id="engagement_fsli_id" name="engagement_fsli_id" class="input"><option value="">— Engagement (planning only) —</option>@foreach ($fslis as $f)<option value="{{ $f->id }}" @selected((string) old('engagement_fsli_id') === (string) $f->id)>{{ $f->code }} · {{ $f->name }}</option>@endforeach</select>
                            @error('engagement_fsli_id')<p class="error">{{ $message }}</p>@enderror</div>
                        <x-field name="title" label="Title" required />
                        <button class="btn-primary btn-sm w-full">Add blank programme</button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
