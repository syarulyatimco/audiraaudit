<x-layouts.app :title="($finding->exists ? 'Edit '.$finding->reference : 'New finding').' · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-fieldwork-nav :engagement="$engagement" />
    @php $t = old('type', $finding->type instanceof \BackedEnum ? $finding->type->value : ($finding->type ?? 'misstatement')); @endphp
    <form method="POST" action="{{ $finding->exists ? route('findings.update', [$engagement, $finding]) : route('findings.store', $engagement) }}" class="card max-w-4xl" x-data="{ type: @js($t) }">
        @csrf @if ($finding->exists) @method('PUT') @endif
        <div class="card-header"><h2 class="card-title">{{ $finding->exists ? "Edit finding {$finding->reference}" : 'Record a finding' }}</h2></div>
        <div class="card-body space-y-5">
            @if ($finding->exists)
                <input type="hidden" name="type" value="{{ $t }}"><p class="text-sm text-ink-600">Type: <span class="font-medium">{{ $finding->type->label() }}</span></p>
            @else
                <x-select name="type" label="Type" :options="\App\Enums\FindingType::options()" :value="$t" x-model="type" required class="max-w-xs" />
            @endif
            <x-field name="title" label="Title" :value="$finding->title" required />
            <x-field name="description" label="What was found — condition and evidence" type="textarea" rows="5" :value="$finding->description" required />
            <div class="grid gap-4 sm:grid-cols-3">
                <x-select name="engagement_fsli_id" label="Line item (FSLI)" :options="$fslis->pluck('name', 'id')->all()" :value="$finding->engagement_fsli_id" placeholder="None" />
                <x-select name="procedure_id" label="Procedure" :options="$procedures->mapWithKeys(fn ($p) => [$p->id => $p->reference.' '.\Illuminate\Support\Str::limit($p->title, 40)])->all()" :value="$finding->procedure_id" placeholder="None" />
                <x-select name="sampling_plan_id" label="Sampling plan" :options="$plans->mapWithKeys(fn ($p) => [$p->id => $p->reference.' '.\Illuminate\Support\Str::limit($p->title, 40)])->all()" :value="$finding->sampling_plan_id" placeholder="None" />
            </div>

            <div x-show="type === 'misstatement'" class="grid gap-4 rounded-md border border-navy-100 p-4 sm:grid-cols-2">
                <x-select name="misstatement_class" label="Class" :options="\App\Enums\MisstatementClass::options()" :value="$finding->misstatement_class" placeholder="Choose…"
                    hint="Factual: no doubt. Judgemental: differences in estimates or policies. Projected: best estimate from a sample." />
                <x-field name="amount" label="Misstatement (RM)" :value="$finding->amount_sen !== null ? number_format($finding->amount_sen / 100, 2, '.', '') : ''"
                    hint="Recorded minus audited amount. Positive = the line item is overstated; negative = understated." />
            </div>

            <div x-show="type === 'control_deficiency'" class="grid gap-4 rounded-md border border-navy-100 p-4 sm:grid-cols-2">
                <x-select name="deficiency_severity" label="Evaluation" :options="\App\Enums\DeficiencySeverity::options()" :value="$finding->deficiency_severity" placeholder="Choose…"
                    hint="Significant deficiencies are communicated in writing to those charged with governance." />
                <label class="mt-6 flex items-center gap-2 text-sm"><input type="hidden" name="report_to_management" value="0"><input type="checkbox" name="report_to_management" value="1" class="checkbox" @checked(old('report_to_management', $finding->report_to_management))> Communicate to management</label>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="cause" label="Cause" type="textarea" rows="3" :value="$finding->cause" />
                <x-field name="recommendation" label="Recommendation" type="textarea" rows="3" :value="$finding->recommendation" />
            </div>
            <x-field name="management_response" label="Management's response" type="textarea" rows="2" :value="$finding->management_response" />
            <label class="flex items-start gap-2 text-sm"><input type="hidden" name="is_significant" value="0"><input type="checkbox" name="is_significant" value="1" class="checkbox mt-0.5" @checked(old('is_significant', $finding->is_significant))>
                <span>Significant finding <span class="block text-xs text-ink-500">Your judgement. A significant finding must be approved by the engagement partner; changing it afterwards needs a fresh approval.</span></span></label>
        </div>
        <div class="flex justify-end gap-2 border-t border-navy-100/70 px-5 py-3">
            <a href="{{ $finding->exists ? route('findings.show', [$engagement, $finding]) : route('findings.index', $engagement) }}" class="btn-secondary">Cancel</a>
            <button class="btn-primary">{{ $finding->exists ? 'Save changes' : 'Record finding' }}</button>
        </div>
    </form>
</x-layouts.app>
