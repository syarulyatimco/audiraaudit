<x-layouts.app title="Account">
    <x-page-header title="Account & security" subtitle="Your sign-in details." />
    @if (session('recovery_codes'))
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-5 py-4" x-data="copyText(@js(implode("\n", session('recovery_codes'))))">
            <div class="text-sm font-semibold text-amber-900">Save your recovery codes now — they are shown only once.</div>
            <p class="mt-1 text-xs text-amber-900/80">Each code works once if you lose access to your authenticator app.</p>
            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">@foreach (session('recovery_codes') as $c)<code class="rounded bg-white px-2 py-1 text-center font-mono text-xs text-navy-900">{{ $c }}</code>@endforeach</div>
            <button type="button" class="btn-secondary btn-sm mt-3" @click="copy()" x-text="copied ? 'Copied' : 'Copy codes'">Copy codes</button>
        </div>
    @endif
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-1">
            <div class="card-header"><h2 class="card-title">Profile</h2></div>
            <dl class="card-body">
                <div class="dl-row"><dt>Name</dt><dd>{{ $user->name }}</dd></div>
                <div class="dl-row"><dt>Email</dt><dd>{{ $user->email }}</dd></div>
                <div class="dl-row"><dt>Role</dt><dd>{{ $user->roleLabel() }}</dd></div>
                <div class="dl-row"><dt>Last sign-in</dt><dd>{{ local_time($user->last_login_at) }}</dd></div>
            </dl>
        </div>
        <div class="card lg:col-span-3 order-last">
            <div class="card-header"><h2 class="card-title">Two-factor authentication</h2>
                @if ($user->hasMfaEnabled())<span class="badge badge-success badge-dot">On</span>@else<span class="badge badge-warn badge-dot">Off</span>@endif</div>
            <div class="card-body">
                @if ($user->hasMfaEnabled())
                    <p class="text-sm text-ink-600">Enabled {{ local_time($user->mfa_enabled_at) }}. {{ count($user->mfa_recovery_codes ?? []) }} recovery codes remaining.</p>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <form method="POST" action="{{ route('mfa.recovery') }}" class="flex items-end gap-2">@csrf
                            <x-field class="flex-1" name="current_password" label="Current password" type="password" required autocomplete="current-password" />
                            <button class="btn-secondary">New recovery codes</button></form>
                        @unless ($user->requiresMfa())
                            <form method="POST" action="{{ route('mfa.disable') }}" class="flex items-end gap-2">@csrf @method('DELETE')
                                <input type="password" name="current_password" placeholder="Current password" class="input flex-1" required autocomplete="current-password">
                                <button class="btn-danger">Turn off</button></form>
                        @else
                            <p class="self-end text-xs text-ink-500">Two-factor authentication is mandatory for your role.</p>
                        @endunless
                    </div>
                @else
                    <p class="text-sm text-ink-600">Add a second step to sign-in using an authenticator app.@if ($user->requiresMfa()) <strong>Required for your role.</strong>@endif</p>
                    <a href="{{ route('mfa.setup') }}" class="btn-primary mt-4">Set up two-factor authentication</a>
                @endif
            </div>
        </div>
        <div class="card lg:col-span-2">
            <div class="card-header"><h2 class="card-title">Change password</h2></div>
            <form method="POST" action="{{ route('account.password') }}" class="card-body grid gap-5 sm:grid-cols-2">
                @csrf @method('PUT')
                <x-field class="sm:col-span-2" name="current_password" label="Current password" type="password" required autocomplete="current-password" />
                <x-field name="password" label="New password" type="password" required autocomplete="new-password" hint="Min. 12 characters with upper & lower case, a number and a symbol." />
                <x-field name="password_confirmation" label="Confirm new password" type="password" required autocomplete="new-password" />
                <div class="sm:col-span-2"><button class="btn-primary">Update password</button></div>
            </form>
        </div>
    </div>
</x-layouts.app>
