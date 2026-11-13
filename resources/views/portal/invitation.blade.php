<x-layouts.guest title="Client portal">
    <h1 class="text-2xl font-semibold">Set up portal access</h1>
    <p class="mt-2 text-sm text-ink-600">{{ $invitation->name }}, you've been invited to the client portal for <span class="font-medium">{{ $invitation->client->name }}</span>. Choose a password for {{ $invitation->email }}.</p>
    <form method="POST" action="{{ route('portal.invitation.accept', $token) }}" class="mt-6 space-y-4">
        @csrf
        @error('token')<p class="error">{{ $message }}</p>@enderror
        <x-field name="password" label="Password" type="password" required hint="At least 12 characters with upper and lower case, a number and a symbol." />
        <x-field name="password_confirmation" label="Confirm password" type="password" required />
        <button class="btn-primary w-full">Activate access</button>
    </form>
</x-layouts.guest>
