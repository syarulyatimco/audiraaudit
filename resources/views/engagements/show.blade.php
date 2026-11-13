<x-layouts.app :title="$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    @if ($canUpdate)<div class="-mt-3 mb-4 flex justify-end"><a href="{{ route('engagements.edit', $engagement) }}" class="btn-secondary btn-sm">Edit details</a></div>@endif

    {{-- Lifecycle --}}
    <div class="card mb-6">
        <div class="flex flex-col gap-4 px-5 py-4 lg:flex-row lg:items-center">
            <ol class="flex flex-1 flex-wrap items-center gap-y-2">
                @foreach (\App\Enums\EngagementStatus::cases() as $i => $stage)
                    @php
                        $cases = \App\Enums\EngagementStatus::cases();
                        $currentIndex = array_search($engagement->status, $cases, true);
                        $state = $i < $currentIndex ? 'done' : ($i === $currentIndex ? 'current' : 'todo');
                    @endphp
                    <li class="flex items-center">
                        <span class="flex items-center gap-2 text-xs font-medium {{ $state === 'current' ? 'text-navy-900' : ($state === 'done' ? 'text-teal-700' : 'text-ink-400') }}">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full text-2xs font-bold {{ $state === 'current' ? 'bg-navy-800 text-white' : ($state === 'done' ? 'bg-teal-100 text-teal-700' : 'bg-navy-50 text-ink-400') }}">{{ $state === 'done' ? '✓' : $i + 1 }}</span>
                            {{ $stage->label() }}
                        </span>
                        @if (! $loop->last)<span class="mx-3 h-px w-6 bg-navy-100"></span>@endif
                    </li>
                @endforeach
            </ol>
            @if ($canChangeStatus)
                <form method="POST" action="{{ route('engagements.status', $engagement) }}" class="flex items-center gap-2">
                    @csrf @method('PUT')
                    <select name="status" class="input w-44 py-1.5 text-sm">
                        @foreach (\App\Enums\EngagementStatus::workingStages() as $stage)
                            @if ($engagement->status->canTransitionTo($stage))<option value="{{ $stage->value }}">Move to {{ $stage->label() }}</option>@endif
                        @endforeach
                    </select>
                    <button class="btn-secondary btn-sm">Update</button>
                </form>
            @endif
        </div>
        @if ($engagement->status === \App\Enums\EngagementStatus::Reporting)
            <p class="border-t border-navy-50 px-5 py-2.5 text-xs text-ink-500">Sign-off is performed through the audit report sign-off approval by the engagement partner (Reporting module).</p>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($alerts->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Engagement health</h2><span class="text-xs text-ink-500">Checked daily</span></div>
                    <ul class="divide-y divide-navy-50 text-sm">
                        @foreach ($alerts as $a)
                            <li class="flex items-start gap-3 px-5 py-3"><x-status :value="$a->severity" /><div class="flex-1">{{ $a->message }}<div class="text-xs text-ink-500">Since {{ local_time($a->first_seen_at, 'j M Y') }}</div></div></li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @include('ai.partials.panel', ['agent' => 'monitoring', 'task' => 'health_digest', 'aiRun' => $healthRun, 'aiUnavailable' => $healthUnavailable, 'title' => 'AI health digest',
                'blurb' => 'The Monitoring agent summarises open alerts and progress into priorities for the team. Advisory only.', 'show' => in_array($myRole?->value, ['engagement_partner', 'manager'], true) && ! $engagement->status->isLocked()])
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Engagement team</h2>
                    @if ($canManageTeam)<button type="button" class="btn-secondary btn-sm" @click="$dispatch('open-modal', 'add-member')">Add member</button>@endif
                </div>
                <table class="table">
                    <thead><tr><th>Name</th><th>Engagement role</th><th>Firm role</th>@if($canManageTeam)<th></th>@endif</tr></thead>
                    <tbody>
                    @foreach ($engagement->members->sortBy(fn ($m) => array_search($m->role, \App\Enums\EngagementRole::cases(), true)) as $m)
                        <tr>
                            <td><div class="font-medium text-navy-900">{{ $m->user->name }}</div><div class="text-xs text-ink-500">{{ $m->user->email }}</div></td>
                            <td><span class="badge {{ $m->role === \App\Enums\EngagementRole::EngagementPartner ? 'badge-teal' : 'badge-neutral' }}">{{ $m->role->label() }}</span></td>
                            <td class="text-ink-600">{{ $m->user->firm_role?->label() }}</td>
                            @if ($canManageTeam)
                                <td class="text-right">
                                    @if ($m->role === \App\Enums\EngagementRole::EngagementPartner)
                                        @if (auth()->user()->hasPermission('engagements.view_all'))
                                            <button type="button" class="btn-ghost btn-sm" @click="$dispatch('open-modal', 'replace-partner')">Reassign</button>
                                        @endif
                                    @elseif ($m->user_id !== auth()->id())
                                        <form method="POST" action="{{ route('engagements.members.destroy', [$engagement, $m]) }}" onsubmit="return confirm('Remove {{ e($m->user->name) }} from this engagement?')">@csrf @method('DELETE')<button class="btn-ghost btn-sm text-red-700">Remove</button></form>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">Audit workflow</h2><span class="text-xs text-ink-500">Modules unlock as they are released</span></div>
                <div class="grid gap-px overflow-hidden rounded-b-lg bg-navy-50 sm:grid-cols-2">
                    @foreach ([
                        ['Understanding the entity', 'Entity, environment and internal control documentation (B1)', route('entity-understanding.show', $engagement)],
                        ['Working papers', 'Index, sign-offs, review notes, evidence and citations', route('working-papers.index', $engagement)],
                        ['Documents', 'Hashed, scanned and versioned evidence', route('documents.index', $engagement)],
                        ['Trial balance & mapping', 'Import TB, map accounts to FSLIs, lead schedules', 'Phase 3'],
                        ['Materiality', 'Overall, performance, specific and clearly trivial — partner approved', 'Phase 5'],
                        ['Risk assessment & strategy', 'Risks by FSLI and assertion, significant risks, audit strategy', 'Phase 5'],
                        ['Programme & sampling', 'Risk-responsive procedures with reproducible, documented sampling', 'Phase 6'],
                        ['PBC, testing & completion', 'Client requests, testing, findings, quality review, reporting', 'Phases 7–9'],
                    ] as [$name, $desc, $target])
                        @if (str_starts_with($target, 'http'))
                            <a href="{{ $target }}" class="bg-white px-5 py-4 transition hover:bg-teal-50/40">
                                <div class="flex items-center justify-between"><span class="text-sm font-medium text-navy-900">{{ $name }}</span><span class="text-xs font-medium text-teal-700">Open →</span></div>
                                <p class="mt-1 text-xs text-ink-500">{{ $desc }}</p>
                            </a>
                        @else
                            <div class="bg-white px-5 py-4">
                                <div class="flex items-center justify-between"><span class="text-sm font-medium text-ink-600">{{ $name }}</span><span class="text-2xs font-semibold uppercase tracking-wide text-ink-400">{{ $target }}</span></div>
                                <p class="mt-1 text-xs text-ink-500">{{ $desc }}</p>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Overview</h2></div>
                <dl class="card-body">
                    <div class="dl-row"><dt>Client</dt><dd><a href="{{ route('clients.show', $engagement->client) }}" class="text-teal-700 hover:text-teal-800">{{ $engagement->client->name }}</a></dd></div>
                    <div class="dl-row"><dt>Status</dt><dd><x-status :value="$engagement->status" /></dd></div>
                    <div class="dl-row"><dt>Reporting deadline</dt><dd>{{ $engagement->reporting_deadline?->format('j M Y') ?? '—' }}</dd></div>
                    <div class="dl-row"><dt>Your role</dt><dd>{{ $myRole?->label() ?? 'Firm oversight' }}</dd></div>
                    <div class="dl-row"><dt>Created by</dt><dd>{{ $engagement->creator->name }}</dd></div>
                </dl>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Activity</h2></div>
                <ul class="px-5 py-2">
                    @forelse ($activity as $log)
                        <li class="flex gap-3 py-2 text-sm"><span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-navy-300"></span><span><span class="text-ink-700">{{ $log->description }}</span><span class="block text-xs text-ink-400">{{ $log->actor_label }} · {{ local_time($log->created_at) }}</span></span></li>
                    @empty
                        <li class="py-3 text-sm text-ink-500">No activity yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

    @if ($canManageTeam)
        <x-modal name="add-member" title="Add team member">
            @if ($availableStaff->isEmpty())
                <p class="text-sm text-ink-500">All active staff are already on this engagement.</p>
            @else
                <form method="POST" action="{{ route('engagements.members.store', $engagement) }}" class="space-y-4">
                    @csrf
                    <x-select name="user_id" label="Staff member" :options="$availableStaff->mapWithKeys(fn ($u) => [$u->id => $u->name.' — '.$u->firm_role->label()])->all()" placeholder="Select…" required />
                    <x-select name="role" label="Engagement role" :options="$roles->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all()" value="staff" required hint="EQR reviewers must be partners independent of the engagement team." />
                    <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="open = false">Cancel</button><button class="btn-primary">Add to team</button></div>
                </form>
            @endif
        </x-modal>
        @if (auth()->user()->hasPermission('engagements.view_all'))
            <x-modal name="replace-partner" title="Reassign engagement partner">
                <form method="POST" action="{{ route('engagements.partner', $engagement) }}" class="space-y-4">
                    @csrf @method('PUT')
                    <x-select name="user_id" label="New engagement partner" :options="$partners->reject(fn ($p) => $p->id === $engagement->partner()?->id)->pluck('name', 'id')->all()" placeholder="Select partner…" required />
                    <p class="text-xs text-ink-500">The current partner is removed from the team. This change is recorded in the audit trail.</p>
                    <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="open = false">Cancel</button><button class="btn-primary">Reassign</button></div>
                </form>
            </x-modal>
        @endif
    @endif
</x-layouts.app>
