<x-layouts.app title="Set up two-factor authentication">
    <x-page-header title="Set up two-factor authentication" :subtitle="$required ? 'Required for partners, firm admins and platform administrators.' : 'Protect your account with an authenticator app.'" />
    <div class="card max-w-3xl">
        <div class="card-body grid gap-8 md:grid-cols-[auto_1fr]">
            <div class="flex flex-col items-center gap-3">
                <div class="rounded-lg border border-navy-100 bg-white p-3">{!! $qr !!}</div>
                <div class="text-center"><div class="text-2xs font-semibold uppercase tracking-wider text-ink-500">Manual key</div><code class="mt-1 block font-mono text-xs text-navy-900">{{ $secret }}</code></div>
            </div>
            <div class="space-y-4 text-sm text-ink-700">
                <ol class="list-decimal space-y-2 pl-5">
                    <li>Open an authenticator app (Microsoft Authenticator, Google Authenticator, 1Password, Authy…).</li>
                    <li>Scan the QR code, or enter the manual key.</li>
                    <li>Enter the 6-digit code the app shows to confirm.</li>
                </ol>
                <form method="POST" action="{{ route('mfa.enable') }}" class="flex items-end gap-3 pt-2">
                    @csrf
                    <x-field class="w-48" name="code" label="6-digit code" inputmode="numeric" autocomplete="one-time-code" required autofocus />
                    <button class="btn-primary">Turn on</button>
                </form>
            </div>
        </div>
    </div>
</x-layouts.app>
