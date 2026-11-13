<x-layouts.app title="New engagement">
    <x-page-header title="New engagement" subtitle="Only clients with approved acceptance are available." />
    @if ($clients->isEmpty())
        <div class="card"><x-empty title="No accepted clients" description="Create a client and obtain partner approval of the acceptance assessment first.">
            <a href="{{ route('clients.index') }}" class="btn-primary btn-sm">Go to clients</a></x-empty></div>
    @else
        <form method="POST" action="{{ route('engagements.store') }}" class="card"
              x-data="{ fye: @js($clients->mapWithKeys(fn ($c) => [$c->id => ['m' => $c->fye_month, 'd' => $c->fye_day, 'fw' => $c->reporting_framework->value, 'name' => $c->name]])), client: '{{ old('client_id', $selectedClient?->id) }}',
                       start: '{{ old('period_start') }}', end: '{{ old('period_end') }}', fw: '{{ old('reporting_framework', $selectedClient?->reporting_framework->value) }}', name: @js(old('name', '')),
                       pick() { const c = this.fye[this.client]; if (!c) return; const now = new Date(); let y = now.getFullYear();
                         let end = new Date(Date.UTC(y, c.m - 1, Math.min(c.d, new Date(y, c.m, 0).getDate()))); if (end > now) { y -= 1; end = new Date(Date.UTC(y, c.m - 1, Math.min(c.d, new Date(y, c.m, 0).getDate()))); }
                         const start = new Date(end); start.setUTCFullYear(start.getUTCFullYear() - 1); start.setUTCDate(start.getUTCDate() + 1);
                         this.end = end.toISOString().slice(0, 10); this.start = start.toISOString().slice(0, 10); this.fw = c.fw;
                         if (!this.name) this.name = 'Statutory audit FYE ' + end.getUTCFullYear(); } }"
              x-init="if (client && !end) pick()">
            @csrf
            <div class="card-body grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="label" for="client_id">Client <span class="text-red-500">*</span></label>
                    <select id="client_id" name="client_id" class="input" x-model="client" @change="name=''; pick()" required>
                        <option value="">Select an accepted client…</option>
                        @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }} (FYE {{ $c->fyeLabel() }})</option>@endforeach
                    </select>
                    @error('client_id')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="name">Engagement name <span class="text-red-500">*</span></label>
                    <input id="name" name="name" class="input" x-model="name" required>
                    @error('name')<p class="error">{{ $message }}</p>@enderror
                </div>
                <x-select name="engagement_type" label="Engagement type" :options="$types" value="statutory_audit" required />
                <div>
                    <label class="label" for="reporting_framework">Reporting framework <span class="text-red-500">*</span></label>
                    <select id="reporting_framework" name="reporting_framework" class="input" x-model="fw" required>@foreach ($frameworks as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                </div>
                <div><label class="label" for="period_start">Period start <span class="text-red-500">*</span></label><input type="date" id="period_start" name="period_start" class="input" x-model="start" required>@error('period_start')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label class="label" for="period_end">Period end <span class="text-red-500">*</span></label><input type="date" id="period_end" name="period_end" class="input" x-model="end" required>@error('period_end')<p class="error">{{ $message }}</p>@enderror</div>
                <x-field name="reporting_deadline" label="Reporting deadline" type="date" hint="Internal target or statutory filing deadline." />
                <x-field name="code" label="Engagement code" hint="Leave blank to auto-generate (ENG-YYYY-0001)." />
            </div>
            <div class="grid gap-5 border-t border-navy-100/70 px-5 py-4 sm:grid-cols-2">
                <x-select name="engagement_partner_id" label="Engagement partner" :options="$partners->pluck('name', 'id')->all()" :value="$currentUser->firm_role === \App\Enums\FirmRole::Partner ? $currentUser->id : null" placeholder="Select partner…" required hint="Takes overall responsibility for the engagement." />
                <x-select name="manager_id" label="Engagement manager" :options="$managers->pluck('name', 'id')->all()" placeholder="None for now" />
            </div>
            <div class="flex justify-end gap-2 border-t border-navy-100/70 px-5 py-3">
                <a href="{{ route('engagements.index') }}" class="btn-secondary">Cancel</a>
                <button class="btn-primary">Create engagement</button>
            </div>
        </form>
    @endif
</x-layouts.app>
