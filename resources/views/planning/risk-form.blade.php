<x-layouts.app :title="($risk->exists ? 'Edit risk' : 'New risk').' · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-planning-nav :engagement="$engagement" />
    @php
        $selFslis = old('fsli_ids', $risk->exists ? $risk->fslis->pluck('id')->all() : []);
        $selAssertions = old('assertions', $risk->assertions ?? []);
        $selFactors = old('inherent_factors', $risk->inherent_factors ?? []);
    @endphp
    <form method="POST" action="{{ $risk->exists ? route('risks.update', [$engagement, $risk]) : route('risks.store', $engagement) }}" class="card max-w-4xl"
          x-data="{ level: @js(old('level', $risk->level?->value ?? 'assertion')), significant: @js((bool) old('is_significant', $risk->is_significant)), reliance: @js((bool) old('controls_reliance', $risk->controls_reliance)) }">
        @csrf @if ($risk->exists) @method('PUT') @endif
        <div class="card-header"><h2 class="card-title">{{ $risk->exists ? "Edit {$risk->reference}" : 'New risk of material misstatement' }}</h2>
            @if ($risk->exists && $risk->significance_status === \App\Enums\SignificanceStatus::Approved)<span class="text-xs text-amber-700">Changing the substance of an approved significant risk requires partner approval again.</span>@endif</div>
        <div class="card-body space-y-5">
            <x-field name="title" label="Title" :value="$risk->title" required />
            <x-field name="description" label="Description — what could go wrong, and why" type="textarea" rows="4" :value="$risk->description" required />
            <div>
                <span class="label">Level</span>
                <div class="flex gap-4 text-sm">@foreach (\App\Enums\RiskLevel::options() as $k => $l)<label class="flex items-center gap-2"><input type="radio" name="level" value="{{ $k }}" x-model="level" class="text-teal-600 focus:ring-teal-500">{{ $l }}</label>@endforeach</div>
            </div>
            <div x-show="level === 'assertion'" class="grid gap-5 sm:grid-cols-2">
                <div>
                    <span class="label">FSLIs affected</span>
                    <div class="max-h-56 space-y-1 overflow-y-auto rounded-md border border-navy-100 p-2 text-sm">
                        @foreach ($fslis as $f)<label class="flex items-center gap-2"><input type="checkbox" name="fsli_ids[]" value="{{ $f->id }}" class="checkbox" @checked(in_array($f->id, $selFslis))>{{ $f->name }}</label>@endforeach
                    </div>
                    @error('fsli_ids')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <span class="label">Assertions</span>
                    <div class="space-y-1 text-sm">@foreach (\App\Enums\Assertion::options() as $k => $l)<label class="flex items-center gap-2"><input type="checkbox" name="assertions[]" value="{{ $k }}" class="checkbox" @checked(in_array($k, $selAssertions))>{{ $l }}</label>@endforeach</div>
                    @error('assertions')<p class="error">{{ $message }}</p>@enderror
                </div>
            </div>
            <div>
                <span class="label">Inherent risk factors</span>
                <div class="grid gap-1 text-sm sm:grid-cols-2">@foreach (\App\Enums\InherentRiskFactor::options() as $k => $l)<label class="flex items-center gap-2"><input type="checkbox" name="inherent_factors[]" value="{{ $k }}" class="checkbox" @checked(in_array($k, $selFactors))>{{ $l }}</label>@endforeach</div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-select name="likelihood" label="Likelihood of misstatement" :options="\App\Enums\RiskRating::options()" :value="$risk->likelihood" placeholder="Select…" required />
                <x-select name="magnitude" label="Magnitude of potential misstatement" :options="\App\Enums\RiskRating::options()" :value="$risk->magnitude" placeholder="Select…" required />
            </div>
            <div class="space-y-2 text-sm">
                <input type="hidden" name="is_fraud_risk" value="0"><label class="flex items-center gap-2"><input type="checkbox" name="is_fraud_risk" value="1" class="checkbox" @checked(old('is_fraud_risk', $risk->is_fraud_risk))>Risk of material misstatement due to fraud</label>
                <input type="hidden" name="is_significant" value="0"><label class="flex items-center gap-2"><input type="checkbox" name="is_significant" value="1" class="checkbox" x-model="significant" @disabled($risk->presumed_key === 'management_override')>Significant risk — at the upper end of the spectrum of inherent risk (requires engagement partner approval)</label>
                @if ($risk->presumed_key === 'management_override')<input type="hidden" name="is_significant" value="1">@endif
                @error('is_significant')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div>
                <input type="hidden" name="controls_reliance" value="0"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="controls_reliance" value="1" class="checkbox" x-model="reliance">We plan to test and rely on controls over this risk</label>
                <div x-show="reliance" class="mt-2"><x-field name="controls_description" label="Controls to be relied on" type="textarea" rows="2" :value="$risk->controls_description" /></div>
            </div>
            <x-field name="planned_response" label="Planned response" type="textarea" rows="3" :value="$risk->planned_response" hint="Overall approach to address the risk. Detailed procedures are added in the audit programme. Required for significant risks." />
        </div>
        <div class="flex justify-end gap-2 border-t border-navy-100/70 px-5 py-3">
            <a href="{{ $risk->exists ? route('risks.show', [$engagement, $risk]) : route('risks.index', $engagement) }}" class="btn-secondary">Cancel</a>
            <button class="btn-primary">{{ $risk->exists ? 'Save changes' : 'Record risk' }}</button>
        </div>
    </form>
</x-layouts.app>
