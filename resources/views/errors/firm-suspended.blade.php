<x-layouts.guest title="Workspace suspended">
    <h2 class="text-2xl font-semibold">Workspace suspended</h2>
    <p class="mt-2 text-sm text-ink-600">Access to <strong>{{ $firm->name }}</strong> is currently suspended{{ $firm->suspended_reason ? ': '.$firm->suspended_reason : '.' }}</p>
    <p class="mt-2 text-sm text-ink-600">Your data is retained securely. Please contact AUDIRA support to restore access.</p>
    <form method="POST" action="{{ route('logout') }}" class="mt-6">@csrf<button class="btn-secondary">Sign out</button></form>
</x-layouts.guest>
