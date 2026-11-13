<x-layouts.app :title="'Client requests · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    @php $drafts = $items->where('status', \App\Enums\PbcStatus::Draft); @endphp
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($contacts->isEmpty())
                <p class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $engagement->client->name }} has no active portal users yet. <a class="font-medium underline" href="{{ route('clients.show', $engagement->client) }}">Invite a client contact</a> before sending requests.</p>
            @endif
            <form method="POST" action="{{ route('client-requests.send', $engagement) }}" class="card overflow-x-auto">
                @csrf
                <div class="card-header">
                    <div><h2 class="card-title">Document requests (PBC)</h2><p class="text-xs text-ink-500">{{ $items->filter->isOpen()->count() }} open · {{ $items->filter->isOverdue()->count() }} overdue · {{ $items->where('status', \App\Enums\PbcStatus::Submitted)->count() }} awaiting review</p></div>
                    @if ($canManage && $drafts->isNotEmpty())<button class="btn-accent btn-sm">Send selected to client</button>@endif
                </div>
                @if ($items->isEmpty())
                    <x-empty title="No requests" description="List the documents you need from the client, then send them to the client portal." />
                @else
                    <table class="table text-sm">
                        <thead><tr><th class="w-8"></th><th>Request</th><th>Due</th><th>Files</th><th>Status</th></tr></thead>
                        <tbody>
                        @foreach ($items as $item)
                            <tr>
                                <td>@if ($canManage && $item->status === \App\Enums\PbcStatus::Draft)<input type="checkbox" name="items[]" value="{{ $item->id }}" class="checkbox" checked>@endif</td>
                                <td><span class="font-mono text-xs text-ink-500">{{ $item->reference }}</span> <span class="font-medium text-navy-900">{{ $item->title }}</span>
                                    <div class="text-xs text-ink-500">{{ $item->category->label() }}{{ $item->procedure ? ' · '.$item->procedure->reference : '' }}{{ $item->contact ? ' · '.$item->contact->name : '' }}</div>
                                    @if ($item->review_note && $item->status === \App\Enums\PbcStatus::Returned)<div class="text-xs text-amber-800">Returned: {{ $item->review_note }}</div>@endif</td>
                                <td class="whitespace-nowrap text-xs {{ $item->isOverdue() ? 'font-semibold text-red-700' : '' }}">{{ $item->due_on?->format('j M Y') ?? '—' }}</td>
                                <td class="text-xs">
                                    @foreach ($item->documents as $d)
                                        <div class="flex items-center gap-2"><a href="{{ route('documents.show', [$engagement, $d]) }}" class="text-navy-900 hover:text-teal-700">{{ \Illuminate\Support\Str::limit($d->currentVersion?->original_name, 28) }}</a>
                                            @php $dr = $docRuns->get($d->uuid); @endphp
                                            @if ($dr)<a href="{{ route('ai-runs.show', [$engagement, $dr]) }}" class="text-teal-700">AI ↗</a>@endif</div>
                                    @endforeach
                                </td>
                                <td><x-status :value="$item->status" /></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </form>

            @foreach ($items->where('status', \App\Enums\PbcStatus::Submitted) as $item)
                @if ($canManage)
                    <div class="card">
                        <div class="card-header"><h3 class="card-title">Review {{ $item->reference }} · {{ $item->title }}</h3><span class="text-xs text-ink-500">Submitted {{ $item->submitted_at?->diffForHumans() }}</span></div>
                        <div class="card-body space-y-3 text-sm">
                            @foreach ($item->documents as $d)
                                @php $dr = $docRuns->get($d->uuid); @endphp
                                <div class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-navy-50 px-3 py-2">
                                    <a href="{{ route('documents.show', [$engagement, $d]) }}" class="text-navy-900 hover:text-teal-700">{{ $d->currentVersion?->original_name }}</a>
                                    <div class="flex items-center gap-2">
                                        @if ($dr)@include('ai.partials.run-status', ['run' => $dr])@endif
                                        @if (! $dr || ! $dr->isActive())@include('ai.partials.start', ['agent' => 'document_intelligence', 'task' => 'classify_document', 'subject' => $d->uuid, 'label' => $dr ? 'Re-analyse' : 'Analyse with AI', 'unavailable' => $docUnavailable])@endif
                                    </div>
                                </div>
                                @if ($dr && $dr->isUsable())@include('ai.partials.extraction-results', ['run' => $dr])@endif
                            @endforeach
                            <form method="POST" action="{{ route('client-requests.review', [$engagement, $item]) }}" class="flex flex-wrap items-end gap-2" x-data="{ d: 'accept' }">
                                @csrf
                                <input type="hidden" name="decision" :value="d">
                                <div class="min-w-[16rem] flex-1"><x-field name="note" label="Note to the client (required to return)" /></div>
                                <button class="btn-accent" @click="d = 'accept'">Accept</button>
                                <button class="btn-secondary" @click="d = 'return'">Return to client</button>
                            </form>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>

        <div class="space-y-6">
            @if ($canManage)
                <form method="POST" action="{{ route('client-requests.store', $engagement) }}" class="card">
                    @csrf
                    <div class="card-header"><h2 class="card-title">New request</h2></div>
                    <div class="card-body space-y-3">
                        <x-field name="title" label="What do you need?" required />
                        <x-field name="description" label="Details for the client" type="textarea" rows="3" />
                        <x-select name="category" label="Category" :options="\App\Enums\PbcCategory::options()" value="other" />
                        <x-field name="due_on" label="Due date" type="date" />
                        <x-select name="assigned_contact_id" label="Client contact" :options="$contacts->pluck('name', 'id')->all()" placeholder="All portal users" />
                        <x-select name="procedure_id" label="For procedure" :options="$procedures->mapWithKeys(fn ($p) => [$p->id => $p->reference.' '.$p->title])->all()" placeholder="None" />
                    </div>
                    <div class="flex justify-end border-t border-navy-100/70 px-5 py-3"><button class="btn-primary">Add draft request</button></div>
                </form>
            @endif

            @if ($canMessage)
                @include('ai.partials.panel', ['agent' => 'client_communication', 'task' => 'draft_request_message', 'title' => 'AI reminder draft',
                    'blurb' => 'Drafts a courteous message about outstanding requests. It is saved as a draft; you edit and send it.', 'show' => true])
            @endif

            <div class="card">
                <div class="card-header"><h2 class="card-title">Messages</h2></div>
                @if ($canMessage)
                    <form method="POST" action="{{ route('messages.store', $engagement) }}" class="card-body space-y-3 border-b border-navy-50">@csrf
                        <x-field name="subject" label="Subject" required /><x-field name="body" label="Message" type="textarea" rows="3" required />
                        <div class="flex justify-end"><button class="btn-secondary btn-sm">Save draft</button></div></form>
                @endif
                <ul class="divide-y divide-navy-50 text-sm">
                    @forelse ($messages as $m)
                        <li class="px-5 py-3">
                            @if ($m->status === 'draft' && $canMessage)
                                <form method="POST" action="{{ route('messages.update', [$engagement, $m]) }}" class="space-y-2">@csrf @method('PUT')
                                    <div class="flex items-center gap-2 text-xs"><span class="badge badge-warn">Draft</span>@if($m->author_type === 'agent')<span class="badge badge-teal">AI draft</span>@endif</div>
                                    <input name="subject" class="input text-sm" value="{{ $m->subject }}">
                                    <textarea name="body" rows="6" class="input text-sm">{{ $m->body }}</textarea>
                                    <div class="flex justify-end gap-2"><button name="action" value="discard" class="btn-ghost btn-sm text-red-700">Discard</button><button name="action" value="save" class="btn-secondary btn-sm">Save</button><button name="action" value="send" class="btn-accent btn-sm">Send to client</button></div>
                                </form>
                            @elseif ($m->status === 'sent')
                                <div class="flex justify-between text-xs text-ink-500"><span>{{ $m->direction === 'inbound' ? 'From '.$m->author?->name.' (client)' : 'Sent by '.$m->sender?->name }}{{ $m->author_type === 'agent' ? ' · AI draft' : '' }}</span><span>{{ local_time($m->sent_at) }}</span></div>
                                <div class="font-medium text-navy-900">{{ $m->subject }}</div>
                                <p class="mt-1 whitespace-pre-line text-ink-700">{{ $m->body }}</p>
                            @endif
                        </li>
                    @empty
                        <li class="px-5 py-3 text-ink-500">No messages.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-layouts.app>
