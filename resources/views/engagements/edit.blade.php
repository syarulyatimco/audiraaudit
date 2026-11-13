<x-layouts.app :title="'Edit '.$engagement->code">
    <x-page-header eyebrow="{{ $engagement->client->name }}" title="Edit engagement" :subtitle="$engagement->code" />
    <form method="POST" action="{{ route('engagements.update', $engagement) }}" class="card">
        @csrf @method('PUT')
        <div class="card-body grid gap-5 sm:grid-cols-2">
            <x-field class="sm:col-span-2" name="name" label="Engagement name" :value="$engagement->name" required />
            <x-select name="engagement_type" label="Engagement type" :options="$types" :value="$engagement->engagement_type" required />
            <x-select name="reporting_framework" label="Reporting framework" :options="$frameworks" :value="$engagement->reporting_framework" required />
            <x-field name="period_start" label="Period start" type="date" :value="$engagement->period_start->toDateString()" required />
            <x-field name="period_end" label="Period end" type="date" :value="$engagement->period_end->toDateString()" required />
            <x-field name="reporting_deadline" label="Reporting deadline" type="date" :value="$engagement->reporting_deadline?->toDateString()" />
            <x-field name="code" label="Engagement code" :value="$engagement->code" required />
        </div>
        <div class="flex justify-end gap-2 border-t border-navy-100/70 px-5 py-3">
            <a href="{{ route('engagements.show', $engagement) }}" class="btn-secondary">Cancel</a>
            <button class="btn-primary">Save changes</button>
        </div>
    </form>
</x-layouts.app>
