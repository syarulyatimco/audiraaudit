<x-layouts.app title="New working paper">
    <x-engagement-nav :engagement="$engagement" />
    <form method="POST" action="{{ route('working-papers.store', $engagement) }}" class="card max-w-3xl"
          x-data="{ suggested: @js($suggested), section: '{{ old('section', $section->value) }}', ref: @js(old('reference', $suggested[$section->value])) }">
        @csrf
        <div class="card-header"><h2 class="card-title">New working paper</h2></div>
        <div class="card-body grid gap-5 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label class="label" for="section">Section <span class="text-red-500">*</span></label>
                <select id="section" name="section" class="input" x-model="section" @change="ref = suggested[section]">
                    @foreach (\App\Enums\WorkingPaperSection::cases() as $s)<option value="{{ $s->value }}">{{ $s->prefix() }} · {{ $s->label() }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="label" for="reference">Reference <span class="text-red-500">*</span></label>
                <input id="reference" name="reference" class="input font-mono" x-model="ref" required>
                @error('reference')<p class="error">{{ $message }}</p>@enderror
            </div>
            <x-field class="sm:col-span-3" name="title" label="Title" required placeholder="e.g. Bank and cash — confirmations and reconciliations" />
            <x-field class="sm:col-span-3" name="objective" label="Objective" type="textarea" rows="3" hint="What this working paper sets out to achieve." />
        </div>
        <div class="flex justify-end gap-2 border-t border-navy-100/70 px-5 py-3">
            <a href="{{ route('working-papers.index', $engagement) }}" class="btn-secondary">Cancel</a>
            <button class="btn-primary">Create working paper</button>
        </div>
    </form>
</x-layouts.app>
