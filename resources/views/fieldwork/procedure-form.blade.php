<x-layouts.app :title="($procedure->exists ? 'Edit procedure' : 'New procedure').' · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-fieldwork-nav :engagement="$engagement" />
    @php $selRisks = old('risk_ids', $procedure->exists ? $procedure->risks->pluck('id')->all() : array_filter([request('risk')])); $selAss = old('assertions', $procedure->assertions ?? []); @endphp
    <form method="POST" action="{{ $procedure->exists ? route('programme.update', [$engagement, $procedure]) : route('programme.store', $engagement) }}" class="card max-w-4xl">
        @csrf @if ($procedure->exists) @method('PUT') @endif
        <div class="card-header"><h2 class="card-title">{{ $procedure->exists ? "Edit {$procedure->reference}" : 'New audit procedure' }}</h2></div>
        <div class="card-body space-y-5">
            <x-field name="title" label="Title" :value="$procedure->title" required />
            <x-field name="steps" label="Steps — what to do and what evidence to obtain" type="textarea" rows="7" :value="$procedure->steps" required hint="Sample sizes are not set here: create a sampling plan for the procedure." />
            <div class="grid gap-4 sm:grid-cols-3">
                <x-select name="nature" label="Nature" :options="\App\Enums\ProcedureNature::options()" :value="$procedure->nature" required />
                <x-select name="timing" label="Timing" :options="\App\Enums\ProcedureTiming::options()" :value="$procedure->timing" required />
                <x-select name="engagement_fsli_id" label="FSLI" :options="$fslis->pluck('name', 'id')->all()" :value="$procedure->engagement_fsli_id" placeholder="None" />
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <span class="label">Responds to risks</span>
                    <div class="max-h-56 space-y-1 overflow-y-auto rounded-md border border-navy-100 p-2 text-sm">
                        @forelse ($risks as $r)<label class="flex items-start gap-2"><input type="checkbox" name="risk_ids[]" value="{{ $r->id }}" class="checkbox mt-0.5" @checked(in_array($r->id, $selRisks))><span><span class="font-mono text-xs">{{ $r->reference }}</span> {{ $r->title }}@if($r->is_significant) <span class="badge badge-danger">Significant</span>@endif</span></label>
                        @empty<p class="text-ink-500">No risks recorded yet.</p>@endforelse
                    </div>
                    @error('risk_ids')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <span class="label">Assertions</span>
                    <div class="space-y-1 text-sm">@foreach (\App\Enums\Assertion::options() as $k => $l)<label class="flex items-center gap-2"><input type="checkbox" name="assertions[]" value="{{ $k }}" class="checkbox" @checked(in_array($k, $selAss))>{{ $l }}</label>@endforeach</div>
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-select name="assigned_to" label="Assigned to" :options="$team->mapWithKeys(fn ($m) => [$m->user_id => $m->user->name.' ('.$m->role->label().')'])->all()" :value="$procedure->assigned_to" placeholder="Unassigned" />
                <x-field name="budget_hours" label="Budget hours" type="number" step="0.25" :value="$procedure->budget_hours" />
            </div>
        </div>
        <div class="flex justify-end gap-2 border-t border-navy-100/70 px-5 py-3">
            <a href="{{ $procedure->exists ? route('programme.show', [$engagement, $procedure]) : route('programme.index', $engagement) }}" class="btn-secondary">Cancel</a>
            <button class="btn-primary">{{ $procedure->exists ? 'Save changes' : 'Add procedure' }}</button>
        </div>
    </form>
</x-layouts.app>
