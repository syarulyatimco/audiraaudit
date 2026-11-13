<x-layouts.guest title="Sign in">
    <h2 class="text-2xl font-semibold">Sign in</h2>
    <p class="mt-1 text-sm text-ink-500">Access your firm's audit workspace.</p>
    <form method="POST" action="{{ url('/login') }}" class="mt-8 space-y-5">
        @csrf
        <x-field name="email" label="Email" type="email" autocomplete="username" required autofocus />
        <x-field name="password" label="Password" type="password" autocomplete="current-password" required />
        <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" name="remember" value="1" class="checkbox"> Keep me signed in on this device</label>
        <button class="btn-primary w-full py-2.5">Sign in</button>
    </form>
    <p class="mt-8 text-center text-sm text-ink-500">New to AUDIRA? <a href="{{ route('register') }}" class="font-medium text-teal-700 hover:text-teal-800">Register your firm</a></p>
</x-layouts.guest>
