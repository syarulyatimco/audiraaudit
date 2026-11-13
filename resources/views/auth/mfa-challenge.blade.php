<x-layouts.guest title="Two-factor verification">
    <h2 class="text-2xl font-semibold">Two-factor verification</h2>
    <p class="mt-1 text-sm text-ink-500">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
    <form method="POST" action="{{ route('mfa.verify') }}" class="mt-8 space-y-5">
        @csrf
        <x-field name="code" label="Authentication code" inputmode="numeric" autocomplete="one-time-code" required autofocus />
        <button class="btn-primary w-full py-2.5">Verify</button>
    </form>
    <form method="POST" action="{{ route('logout') }}" class="mt-6 text-center">@csrf<button class="text-sm text-ink-500 hover:text-navy-900">Sign out</button></form>
</x-layouts.guest>
