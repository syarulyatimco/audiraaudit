<x-layouts.app :title="$client->name">
    <x-page-header eyebrow="Client" :title="$client->name" subtitle="{{ $client->entity_type->label() }} · {{ $client->reporting_framework->label() }} · FYE {{ $client->fyeLabel() }}">
        <x-slot:actions>
            <x-status :value="$client->status" class="mr-2" />
            @can('update', $client)<a href="{{ route('clients.edit', $client) }}" class="btn-secondary">Edit</a>@endcan
            @if ($client->isAccepted())
                @can('create', \App\Models\Engagement::class)<a href="{{ route('engagements.create', ['client' => $client->uuid]) }}" class="btn-primary">New engagement</a>@endcan
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Acceptance & continuance --}}
            <div class="card">
                <div class="card-header"><h2 class="card-title">Acceptance & continuance</h2>@if($client->acceptance_risk)<span class="text-xs text-ink-500">Risk <x-status :value="$client->acceptance_risk" /></span>@endif</div>
                <div class="card-body">
                    @if ($pendingApproval)
                        <div class="rounded-md border border-amber-200 bg-amber-50 p-4">
                            <div class="text-sm font-semibold text-amber-900">Awaiting partner approval</div>
                            <p class="mt-1 text-sm text-amber-900/80">Submitted by {{ $pendingApproval->requester->name }} {{ $pendingApproval->requested_at->diffForHumans() }}.</p>
                            <p class="mt-3 whitespace-pre-line text-sm text-ink-700">{{ $pendingApproval->request_comment }}</p>
                            @if ($canDecide)
                                <form method="POST" action="{{ route('approvals.decide', $pendingApproval) }}" class="mt-4 space-y-3" x-data="{ decision: 'approve' }">
                                    @csrf
                                    <x-field name="decision_comment" label="Decision note" type="textarea" rows="2" hint="Required when rejecting." />
                                    <input type="hidden" name="decision" :value="decision">
                                    <div class="flex gap-2">
                                        <button class="btn-accent" @click="decision = 'approve'">Approve acceptance</button>
                                        <button class="btn-danger" @click="decision = 'reject'">Reject</button>
                                    </div>
                                </form>
                            @elseif ($pendingApproval->requested_by === auth()->id())
                                <form method="POST" action="{{ route('approvals.withdraw', $pendingApproval) }}" class="mt-4">@csrf<button class="btn-secondary btn-sm">Withdraw request</button></form>
                            @endif
                        </div>
                    @elseif ($client->isAccepted())
                        <p class="text-sm text-ink-700">Accepted on {{ local_time($client->accepted_at, 'j M Y') }}. Independence confirmed.</p>
                        @if ($client->acceptance_notes)<p class="mt-2 whitespace-pre-line text-sm text-ink-500">{{ $client->acceptance_notes }}</p>@endif
                    @else
                        @can('submitAcceptance', $client)
                            <form method="POST" action="{{ route('clients.acceptance', $client) }}" class="space-y-4">
                                @csrf
                                <p class="text-sm text-ink-600">Document the acceptance assessment — integrity of management, the firm's competence and resources, ethical and independence considerations — and route it to a partner. You cannot approve your own request.</p>
                                <x-select name="acceptance_risk" label="Engagement risk assessment" :options="\App\Enums\AcceptanceRisk::options()" :value="$client->acceptance_risk" placeholder="Select…" required />
                                <x-field name="acceptance_notes" label="Basis for acceptance" type="textarea" rows="4" :value="$client->acceptance_notes" required />
                                <label class="flex items-start gap-2 text-sm text-ink-700"><input type="checkbox" name="independence_confirmed" value="1" class="checkbox mt-0.5">I confirm that the firm and the proposed engagement team are independent of this client and no threats remain unaddressed.</label>
                                @error('independence_confirmed')<p class="error">{{ $message }}</p>@enderror
                                <button class="btn-primary">Submit for partner approval</button>
                            </form>
                        @else
                            <p class="text-sm text-ink-500">Acceptance has not been requested yet. A partner or manager can submit the assessment.</p>
                        @endcan
                    @endif
                </div>
            </div>

            {{-- Engagements --}}
            <div class="card">
                <div class="card-header"><h2 class="card-title">Engagements</h2></div>
                @if ($engagements->isEmpty())
                    <x-empty title="No engagements" :description="$client->isAccepted() ? 'Create the first engagement for this client.' : 'Available once client acceptance is approved.'" />
                @else
                    <table class="table">
                        <thead><tr><th>Code</th><th>Engagement</th><th>Period</th><th>Status</th></tr></thead>
                        <tbody>
                        @foreach ($engagements as $e)
                            <tr class="row-link"><td class="font-mono text-xs">{{ $e->code }}</td><td><a href="{{ route('engagements.show', $e) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $e->name }}</a></td><td class="tabular-nums">{{ $e->periodLabel() }}</td><td><x-status :value="$e->status" /></td></tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- Approval history --}}
            @if ($client->approvals->where('status', '!=', \App\Enums\ApprovalStatus::Pending)->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Approval history</h2></div>
                    <ul class="divide-y divide-navy-50">
                        @foreach ($client->approvals->reject(fn ($a) => $a->isPending()) as $a)
                            <li class="px-5 py-3 text-sm">
                                <div class="flex items-center justify-between"><span class="font-medium text-navy-900">{{ $a->type->label() }}</span><x-status :value="$a->status" /></div>
                                <div class="text-xs text-ink-500">Requested by {{ $a->requester->name }} {{ local_time($a->requested_at) }}@if($a->decider) · decided by {{ $a->decider->name }} {{ local_time($a->decided_at) }}@endif</div>
                                @if ($a->decision_comment)<p class="mt-1 text-ink-600">“{{ $a->decision_comment }}”</p>@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Details</h2></div>
                <dl class="card-body">
                    <div class="dl-row"><dt>Registration no.</dt><dd class="font-mono text-xs">{{ $client->registration_no ?? '—' }}</dd></div>
                    <div class="dl-row"><dt>Industry</dt><dd>{{ $client->industry ?? '—' }}</dd></div>
                    <div class="dl-row"><dt>Email</dt><dd>{{ $client->email ?? '—' }}</dd></div>
                    <div class="dl-row"><dt>Phone</dt><dd>{{ $client->phone ?? '—' }}</dd></div>
                    <div class="dl-row"><dt>Created by</dt><dd>{{ $client->creator->name }}</dd></div>
                </dl>
                @if ($client->address)<p class="whitespace-pre-line border-t border-navy-50 px-5 py-3 text-sm text-ink-600">{{ $client->address }}</p>@endif
            </div>

            <div class="card" x-data="{ adding: false }">
                <div class="card-header"><h2 class="card-title">Contacts</h2>@can('update', $client)<button type="button" class="btn-ghost btn-sm" @click="adding = !adding">Add</button>@endcan</div>
                @can('update', $client)
                    <form x-show="adding" x-cloak method="POST" action="{{ route('clients.contacts.store', $client) }}" class="space-y-3 border-b border-navy-50 px-5 py-4">
                        @csrf
                        <input name="name" placeholder="Name" class="input" required>
                        <input name="position" placeholder="Position (e.g. Director, Accountant)" class="input">
                        <input name="email" type="email" placeholder="Email" class="input">
                        <input name="phone" placeholder="Phone" class="input">
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_primary" value="1" class="checkbox"> Primary contact</label>
                        <button class="btn-primary btn-sm">Save contact</button>
                    </form>
                @endcan
                <ul class="divide-y divide-navy-50">
                    @forelse ($client->contacts as $ct)
                        <li class="flex items-start justify-between gap-3 px-5 py-3 text-sm">
                            <div><div class="font-medium text-navy-900">{{ $ct->name }}@if($ct->is_primary)<span class="badge badge-teal ml-1">Primary</span>@endif</div><div class="text-xs text-ink-500">{{ collect([$ct->position, $ct->email, $ct->phone])->filter()->join(' · ') }}</div></div>
                            @can('update', $client)
                                <form method="POST" action="{{ route('clients.contacts.destroy', [$client, $ct]) }}" onsubmit="return confirm('Remove this contact?')">@csrf @method('DELETE')<button class="text-xs text-ink-400 hover:text-red-700">Remove</button></form>
                            @endcan
                        </li>
                    @empty
                        <li class="px-5 py-4 text-sm text-ink-500">No contacts recorded.</li>
                    @endforelse
                </ul>
            </div>

            @if ($client->isAccepted())
                @php $portalUsers = $client->portalUsers()->get(); $pending = $client->portalInvitations()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->get(); @endphp
                <div class="card" x-data="{ inviting: false }">
                    <div class="card-header"><h2 class="card-title">Client portal</h2>@can('clients.manage_portal')<button type="button" class="btn-ghost btn-sm" @click="inviting = !inviting">Invite</button>@endcan</div>
                    @can('clients.manage_portal')
                        <form x-show="inviting" x-cloak method="POST" action="{{ route('clients.portal.invite', $client) }}" class="space-y-3 border-b border-navy-50 px-5 py-4">
                            @csrf
                            <x-field name="name" label="Name" required /><x-field name="email" label="Email" type="email" required />
                            <p class="text-xs text-ink-500">Portal users are free. They see only requests and messages for this client.</p>
                            <button class="btn-primary btn-sm">Send invitation</button>
                        </form>
                    @endcan
                    <ul class="divide-y divide-navy-50 text-sm">
                        @foreach ($portalUsers as $pu)
                            <li class="flex items-center justify-between px-5 py-3"><div><div class="font-medium">{{ $pu->name }}</div><div class="text-xs text-ink-500">{{ $pu->email }}</div></div>
                                <div class="flex items-center gap-2"><x-status :value="$pu->status" />@can('clients.manage_portal')<form method="POST" action="{{ route('clients.portal.toggle', [$client, $pu]) }}">@csrf<button class="text-xs text-ink-500 hover:text-navy-900">{{ $pu->isActive() ? 'Remove access' : 'Restore' }}</button></form>@endcan</div></li>
                        @endforeach
                        @foreach ($pending as $inv)
                            <li class="flex items-center justify-between px-5 py-3"><div><div class="font-medium">{{ $inv->name }}</div><div class="text-xs text-ink-500">{{ $inv->email }} · invited, expires {{ $inv->expires_at->diffForHumans() }}</div></div>
                                @can('clients.manage_portal')<form method="POST" action="{{ route('clients.portal.revoke', [$client, $inv]) }}">@csrf<button class="text-xs text-ink-500 hover:text-red-700">Revoke</button></form>@endcan</li>
                        @endforeach
                        @if ($portalUsers->isEmpty() && $pending->isEmpty())<li class="px-5 py-4 text-sm text-ink-500">No portal users.</li>@endif
                    </ul>
                </div>
            @endif

            @can('delete', $client)
                @if ($engagements->isEmpty())
                    <form method="POST" action="{{ route('clients.destroy', $client) }}" onsubmit="return confirm('Delete this client? This cannot be undone from the app.')">@csrf @method('DELETE')<button class="btn-danger w-full">Delete client</button></form>
                @endif
            @endcan
        </div>
    </div>
</x-layouts.app>
