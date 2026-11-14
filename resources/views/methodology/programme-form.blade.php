@php $p = $programme; @endphp
<form method="POST" action="{{ $action }}" class="card-body space-y-3" x-data="{ scope: '{{ old('scope', $p?->scope ?? 'fsli') }}' }">
    @csrf @if ($method !== 'POST') @method($method) @endif
    <div class="grid grid-cols-2 gap-3">
        <div><label class="label" for="stage">Stage</label>
            <select id="stage" name="stage" class="input">@foreach ($stages as $v => $l)<option value="{{ $v }}" @selected(old('stage', $p?->stage?->value) === $v)>{{ $l }}</option>@endforeach</select>
            @error('stage')<p class="error">{{ $message }}</p>@enderror</div>
        <x-field name="ref_prefix" label="Reference" :value="$p?->ref_prefix" required maxlength="12" />
    </div>
    <x-field name="title" label="Title" :value="$p?->title" required />
    <div><label class="label" for="scope">Routed to</label>
        <select id="scope" name="scope" class="input" x-model="scope">
            <option value="engagement">Whole engagement</option>
            <option value="fsli">FSLI codes</option>
            <option value="classification">FSLI classification (fall-back)</option>
        </select>
        @error('scope')<p class="error">{{ $message }}</p>@enderror</div>
    <div x-show="scope === 'fsli'"><x-field name="fsli_codes" label="FSLI codes" :value="implode(', ', $p?->fsli_codes ?? [])" hint="Comma-separated, e.g. TR or REV, TR. The sheet attaches to the first code present." /></div>
    <div x-show="scope === 'classification'"><label class="label" for="fsli_classification">Classification</label>
        <select id="fsli_classification" name="fsli_classification" class="input"><option value="">—</option>@foreach ($classes as $v => $l)<option value="{{ $v }}" @selected(old('fsli_classification', $p?->fsli_classification?->value) === $v)>{{ $l }}</option>@endforeach</select>
        @error('fsli_classification')<p class="error">{{ $message }}</p>@enderror</div>
    <x-field name="objective" label="Objective" type="textarea" rows="3" :value="$p?->objective" />
    <button class="btn-primary btn-sm">{{ $p ? 'Save programme' : 'Add programme' }}</button>
</form>
