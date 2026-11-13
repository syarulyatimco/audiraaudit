<x-layouts.guest title="Join {{ $invitation->firm->name }}">
    <p class="text-2xs font-semibold uppercase tracking-widest text-teal-700">Invitation</p>
    <h2 class="mt-1 text-2xl font-semibold">Join {{ $invitation->firm->name }}</h2>
    <p class="mt-1 text-sm text-ink-500">You've been invited as <strong class="text-navy-900">{{ $invitation->firm_role->label() }}</strong>{{ $invitation->is_firm_admin ? ' with firm admin rights' : '' }}. Set up your account for <strong class="text-navy-900">{{ $invitation->email }}</strong>.</p>
    <form method="POST" action="{{ route('invitations.accept', $token) }}" class="mt-8 space-y-5">
        @csrf
        <x-field name="name" label="Full name" :value="$invitation->name" required />
        <x-field name="password" label="Password" type="password" required autocomplete="new-password" hint="Min. 12 characters with upper & lower case, a number and a symbol." />
        <x-field name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />
        <button class="btn-primary w-full py-2.5">Accept & create account</button>
    </form>
</x-layouts.guest>
