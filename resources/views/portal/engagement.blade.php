<x-layouts.portal :title="$engagement->name">
    <a href="{{ route('portal.dashboard') }}" class="text-xs text-ink-500 hover:text-navy-900">← Overview</a>
    <h1 class="mt-1 text-2xl font-semibold">{{ $engagement->name }}</h1>
    <p class="mt-1 text-sm text-ink-500">{{ $engagement->periodLabel() }}</p>

    <div class="mt-6 space-y-4">
        @forelse ($items as $item)
            <div class="card">
                <div class="card-header">
                    <div><div class="text-xs font-mono text-ink-500">{{ $item->reference }}</div><h2 class="card-title">{{ $item->title }}</h2></div>
                    <div class="text-right"><x-status :value="$item->status" />@if($item->due_on)<div class="mt-1 text-xs {{ $item->isOverdue() ? 'font-semibold text-red-700' : 'text-ink-500' }}">Due {{ $item->due_on->format('j M Y') }}</div>@endif</div>
                </div>
                <div class="card-body space-y-3 text-sm">
                    @if ($item->description)<p class="whitespace-pre-line text-ink-700">{{ $item->description }}</p>@endif
                    @if ($item->status === \App\Enums\PbcStatus::Returned)<p class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900"><span class="font-semibold">Your audit team needs more:</span> {{ $item->review_note }}</p>@endif
                    @if ($item->documents->isNotEmpty())
                        <ul class="text-xs text-ink-600">@foreach ($item->documents as $d)<li>✓ {{ $d->currentVersion?->original_name }} · {{ local_time($d->created_at) }}</li>@endforeach</ul>
                    @endif
                    @if ($item->isOpen() || $item->status === \App\Enums\PbcStatus::Submitted)
                        <form method="POST" action="{{ route('portal.upload', [$engagement, $item]) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2">
                            @csrf
                            <div class="min-w-[14rem] flex-1"><label class="label">Upload a file</label><input type="file" name="file" class="input" required></div>
                            <div class="min-w-[14rem] flex-1"><label class="label">Note (optional)</label><input name="note" class="input"></div>
                            <button class="btn-accent">Upload</button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <x-empty title="No requests" description="Your audit team has not sent any requests yet." />
        @endforelse
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Messages</h2></div>
            <ul class="divide-y divide-navy-50 text-sm">
                @forelse ($messages as $m)
                    <li class="px-5 py-3 {{ $m->direction === 'inbound' ? 'bg-navy-50/40' : '' }}">
                        <div class="flex justify-between text-xs text-ink-500"><span>{{ $m->direction === 'inbound' ? 'You' : ($m->sender?->name ?? 'Audit team') }}</span><span>{{ local_time($m->sent_at) }}</span></div>
                        <div class="font-medium text-navy-900">{{ $m->subject }}</div>
                        <p class="mt-1 whitespace-pre-line text-ink-700">{{ $m->body }}</p>
                    </li>
                @empty
                    <li class="px-5 py-3 text-ink-500">No messages yet.</li>
                @endforelse
            </ul>
        </div>
        <form method="POST" action="{{ route('portal.reply', $engagement) }}" class="card">
            @csrf
            <div class="card-header"><h2 class="card-title">Message your audit team</h2></div>
            <div class="card-body space-y-3"><x-field name="subject" label="Subject" required /><x-field name="body" label="Message" type="textarea" rows="5" required /></div>
            <div class="flex justify-end border-t border-navy-100/70 px-5 py-3"><button class="btn-primary">Send</button></div>
        </form>
    </div>
</x-layouts.portal>
