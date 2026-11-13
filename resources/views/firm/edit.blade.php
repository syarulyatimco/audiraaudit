<x-layouts.app title="Firm profile">
    <x-page-header title="Firm profile" subtitle="Details used on engagement letters, reports and invoices." />
    <form method="POST" action="{{ route('firm.update') }}" class="card">
        @csrf @method('PUT')
        <div class="card-body grid gap-5 sm:grid-cols-2">
            <x-field class="sm:col-span-2" name="name" label="Firm name" :value="$firm->name" required />
            <x-field name="registration_no" label="Registration no. (SSM / LLP)" :value="$firm->registration_no" />
            <x-field name="audit_firm_no" label="Audit firm no. (MIA AF)" :value="$firm->audit_firm_no" />
            <x-field name="email" label="Email" type="email" :value="$firm->email" />
            <x-field name="phone" label="Phone" :value="$firm->phone" />
            <x-field class="sm:col-span-2" name="address_line1" label="Address line 1" :value="$firm->address_line1" />
            <x-field class="sm:col-span-2" name="address_line2" label="Address line 2" :value="$firm->address_line2" />
            <x-field name="city" label="City" :value="$firm->city" />
            <x-field name="state" label="State" :value="$firm->state" />
            <x-field name="postcode" label="Postcode" :value="$firm->postcode" />
            <x-select name="default_framework" label="Default reporting framework" :options="\App\Enums\ReportingFramework::options()" :value="$firm->default_framework" required hint="Pre-selected when creating new clients." />
        </div>
        <div class="flex justify-end border-t border-navy-100/70 px-5 py-3"><button class="btn-primary">Save changes</button></div>
    </form>
</x-layouts.app>
