<x-layouts.guest title="Register your firm" :wide="true">
    <h2 class="text-2xl font-semibold">Register your audit firm</h2>
    <p class="mt-1 text-sm text-ink-500">Start a {{ config('audira.trial_days') }}-day trial. You'll be the firm's first partner and administrator.</p>
    <form method="POST" action="{{ url('/register') }}" class="mt-8 space-y-6">
        @csrf
        <div>
            <div class="label">Plan</div>
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach ($plans as $plan)
                    <label class="relative flex cursor-pointer flex-col rounded-lg border border-navy-100 bg-white p-4 has-[:checked]:border-teal-500 has-[:checked]:ring-1 has-[:checked]:ring-teal-500">
                        <input type="radio" name="plan_id" value="{{ $plan->id }}" class="sr-only" @checked((string) old('plan_id', $selected ? $plans->firstWhere('code', $selected)?->id : $plans->get(1)?->id) === (string) $plan->id)>
                        <span class="text-sm font-semibold text-navy-900">{{ $plan->name }}</span>
                        <span class="mt-1 text-lg font-semibold tabular-nums text-navy-900">{{ money_label($plan->price_per_user_sen, $plan->currency) }}</span>
                        <span class="text-2xs text-ink-500">per user / month · min {{ $plan->min_paid_users }} users</span>
                    </label>
                @endforeach
            </div>
            @error('plan_id')<p class="error">{{ $message }}</p>@enderror
        </div>
        <div class="grid gap-5 sm:grid-cols-2">
            <x-field class="sm:col-span-2" name="firm_name" label="Firm name" required />
            <x-field name="registration_no" label="Firm registration no." hint="SSM / LLP number" />
            <x-field name="audit_firm_no" label="Audit firm no." hint="MIA AF number, if any" />
        </div>
        <div class="grid gap-5 border-t border-navy-100 pt-6 sm:grid-cols-2">
            <x-field class="sm:col-span-2" name="name" label="Your full name" required />
            <x-field class="sm:col-span-2" name="email" label="Work email" type="email" required autocomplete="username" />
            <x-field name="password" label="Password" type="password" required autocomplete="new-password" hint="Min. 12 characters with upper & lower case, a number and a symbol." />
            <x-field name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />
        </div>
        <label class="flex items-start gap-2 text-sm text-ink-600">
            <input type="checkbox" name="terms" value="1" class="checkbox mt-0.5" @checked(old('terms'))>
            <span>I confirm I am authorised to register this firm and agree to the terms of service and data processing terms.</span>
        </label>
        @error('terms')<p class="error">{{ $message }}</p>@enderror
        <button class="btn-primary w-full py-2.5">Create firm workspace</button>
    </form>
    <p class="mt-8 text-center text-sm text-ink-500">Already registered? <a href="{{ route('login') }}" class="font-medium text-teal-700">Sign in</a></p>
</x-layouts.guest>
