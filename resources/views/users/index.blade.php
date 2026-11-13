<x-layouts.app title="People">
    <x-page-header title="People" subtitle="{{ $estimate['active_users'] }} active staff · {{ $estimate['max_users'] ? $estimate['max_users'].' seat limit' : 'no seat limit' }} on {{ $estimate['plan']?->name ?? 'no plan' }}">
        @if ($canManage)
            <x-slot:actions>
                <button type="button" class="btn-primary" @click="$dispatch('open-modal', 'invite')" @disabled(! $seatAvailable)>Invite staff</button>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if (session('invitation_link'))
        <div class="mb-6 rounded-md border border-navy-100 bg-white px-4 py-3 text-sm" x-data="copyText(@js(session('invitation_link')))">
            <div class="font-medium text-navy-900">Invitation link (valid {{ config('audira.invitation_ttl_days') }} days, shown once)</div>
            <div class="mt-2 flex gap-2">
                <input readonly value="{{ session('invitation_link') }}" class="input font-mono text-xs" @focus="$el.select()">
                <button type="button" class="btn-secondary" @click="copy()" x-text="copied ? 'Copied' : 'Copy'">Copy</button>
            </div>
        </div>
    @endif
    @unless ($seatAvailable)
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">All seats on your plan are in use. Deactivate a user or upgrade your plan to invite more staff.</div>
    @endunless

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Last sign-in</th>@if($canManage)<th class="text-right">Actions</th>@endif</tr></thead>
            <tbody>
            @foreach ($users as $u)
                <tr>
                    <td><div class="font-medium text-navy-900">{{ $u->name }}@if($u->is(auth()->user()))<span class="ml-1 text-xs text-ink-400">(you)</span>@endif</div><div class="text-xs text-ink-500">{{ $u->email }}</div></td>
                    <td>{{ $u->firm_role->label() }}@if($u->is_firm_admin)<span class="badge badge-teal ml-1.5">Admin</span>@endif</td>
                    <td><x-status :value="$u->status" /></td>
                    <td class="tabular-nums text-ink-500">{{ $u->last_login_at ? $u->last_login_at->diffForHumans() : 'Never' }}</td>
                    @if ($canManage)
                        <td class="text-right" x-data="{ edit: false }">
                            <div class="flex justify-end gap-2" x-show="!edit">
                                <button type="button" class="btn-secondary btn-sm" @click="edit = true">Change role</button>
                                @unless ($u->is(auth()->user()))
                                    @if ($u->isActive())
                                        <form method="POST" action="{{ route('users.deactivate', $u) }}" onsubmit="return confirm('Deactivate {{ e($u->name) }}? They will be signed out immediately.')">@csrf<button class="btn-danger btn-sm">Deactivate</button></form>
                                    @else
                                        <form method="POST" action="{{ route('users.reactivate', $u) }}">@csrf<button class="btn-secondary btn-sm">Reactivate</button></form>
                                    @endif
                                @endunless
                            </div>
                            <form x-show="edit" x-cloak method="POST" action="{{ route('users.role', $u) }}" class="flex items-center justify-end gap-2">
                                @csrf @method('PUT')
                                <select name="firm_role" class="input w-36 py-1.5 text-xs">@foreach (\App\Enums\FirmRole::options() as $v => $l)<option value="{{ $v }}" @selected($u->firm_role->value === $v)>{{ $l }}</option>@endforeach</select>
                                <label class="flex items-center gap-1 text-xs"><input type="hidden" name="is_firm_admin" value="0"><input type="checkbox" name="is_firm_admin" value="1" class="checkbox" @checked($u->is_firm_admin)> Admin</label>
                                <button class="btn-primary btn-sm">Save</button>
                                <button type="button" class="btn-ghost btn-sm" @click="edit = false">Cancel</button>
                            </form>
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if ($invitations->isNotEmpty())
        <h2 class="mb-3 mt-8 text-sm font-semibold text-navy-900">Pending invitations</h2>
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Email</th><th>Role</th><th>Invited by</th><th>Expires</th>@if($canManage)<th></th>@endif</tr></thead>
                <tbody>
                @foreach ($invitations as $inv)
                    <tr>
                        <td><div class="font-medium text-navy-900">{{ $inv->name }}</div><div class="text-xs text-ink-500">{{ $inv->email }}</div></td>
                        <td>{{ $inv->firm_role->label() }}{{ $inv->is_firm_admin ? ' · Admin' : '' }}</td>
                        <td>{{ $inv->inviter->name }}</td>
                        <td class="tabular-nums">{{ local_time($inv->expires_at, 'j M Y') }}</td>
                        @if ($canManage)
                            <td class="text-right"><form method="POST" action="{{ route('users.invitations.revoke', $inv) }}">@csrf @method('DELETE')<button class="btn-ghost btn-sm text-red-700">Revoke</button></form></td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($canManage)
        <x-modal name="invite" title="Invite a staff member">
            <form method="POST" action="{{ route('users.invite') }}" class="space-y-4">
                @csrf
                <x-field name="name" label="Full name" required />
                <x-field name="email" label="Work email" type="email" required />
                <x-select name="firm_role" label="Firm role" :options="\App\Enums\FirmRole::options()" value="associate" required hint="Partners can approve client acceptance and lead engagements." />
                <label class="flex items-center gap-2 text-sm text-ink-700"><input type="checkbox" name="is_firm_admin" value="1" class="checkbox"> Firm admin (manage people, firm profile)</label>
                <p class="text-xs text-ink-500">Each active staff member is a paid seat at {{ money_label($estimate['price_per_user_sen']) }}/month (minimum {{ $estimate['min_paid_users'] }}).</p>
                <div class="flex justify-end gap-2 pt-2"><button type="button" class="btn-secondary" @click="open = false">Cancel</button><button class="btn-primary">Send invitation</button></div>
            </form>
        </x-modal>
    @endif
</x-layouts.app>
