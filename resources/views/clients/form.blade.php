@php $editing = $client->exists; @endphp
<x-layouts.app :title="$editing ? 'Edit '.$client->name : 'New client'">
    <x-page-header :title="$editing ? 'Edit client' : 'New client'" :subtitle="$editing ? $client->name : 'Record the entity details. Acceptance is requested from the client page.'" />
    <form method="POST" action="{{ $editing ? route('clients.update', $client) : route('clients.store') }}" class="card">
        @csrf @if ($editing) @method('PUT') @endif
        <div class="card-body grid gap-5 sm:grid-cols-2">
            <x-field class="sm:col-span-2" name="name" label="Legal name" :value="$client->name" required />
            <x-field name="registration_no" label="Registration no." :value="$client->registration_no" hint="SSM company / LLP / ROS / SKM number" />
            <x-select name="entity_type" label="Entity type" :options="$entityTypes" :value="$client->entity_type" placeholder="Select…" required />
            <x-select name="reporting_framework" label="Reporting framework" :options="$frameworks" :value="$client->reporting_framework" required />
            <x-field name="industry" label="Industry" :value="$client->industry" />
            <div class="grid grid-cols-2 gap-3">
                <x-select name="fye_month" label="FYE month" :options="collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => date('F', mktime(0, 0, 0, $m, 1))])->all()" :value="$client->fye_month" required />
                <x-field name="fye_day" label="FYE day" type="number" min="1" max="31" :value="$client->fye_day" required />
            </div>
            <x-field name="email" label="Email" type="email" :value="$client->email" />
            <x-field name="phone" label="Phone" :value="$client->phone" />
            <x-field class="sm:col-span-2" name="address" label="Registered address" type="textarea" rows="3" :value="$client->address" />
        </div>
        <div class="flex justify-end gap-2 border-t border-navy-100/70 px-5 py-3">
            <a href="{{ $editing ? route('clients.show', $client) : route('clients.index') }}" class="btn-secondary">Cancel</a>
            <button class="btn-primary">{{ $editing ? 'Save changes' : 'Create client' }}</button>
        </div>
    </form>
</x-layouts.app>
