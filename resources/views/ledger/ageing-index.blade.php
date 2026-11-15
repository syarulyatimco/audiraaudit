<x-layouts.app :title="'Ageing · '.$engagement->code">
    <x-engagement-nav :engagement="$engagement" />
    <x-tb-nav :engagement="$engagement" />
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card overflow-x-auto">
                <div class="card-header"><h2 class="card-title">Ageing listings</h2></div>
                @if ($reports->isEmpty())
                    <x-empty title="No ageing listings yet" description="Import the client's aged receivables and payables listings to reconcile them to the TB and analyse overdue balances." />
                @else
                    <table class="table">
                        <thead><tr><th>Listing</th><th>FSLI</th><th class="text-right">Balances</th><th class="text-right">Total</th><th>Status</th></tr></thead>
                        <tbody>
                        @foreach ($reports as $r)
                            <tr class="row-link"><td><a href="{{ route('ageing.show', [$engagement, $r]) }}" class="font-medium text-navy-900 hover:text-teal-700">{{ $r->label() }}</a></td>
                                <td class="text-xs">{{ $r->fsli?->code ?? '—' }}</td><td class="text-right tabular-nums">{{ $r->status === 'active' ? number_format($r->line_count) : '' }}</td>
                                <td class="text-right tabular-nums">{{ $r->status === 'active' ? amount($r->total_sen, false) : '' }}</td>
                                <td><span class="badge {{ $r->status === 'active' ? 'badge-success' : 'badge-warn' }}">{{ $r->status === 'active' ? 'Imported' : 'Check columns' }}</span></td></tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
        @if ($canImport)
            <form method="POST" action="{{ route('ageing.upload', $engagement) }}" enctype="multipart/form-data" class="card">
                @csrf
                <div class="card-header"><h2 class="card-title">Import a listing</h2></div>
                <div class="card-body space-y-3 text-sm">
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="label">Type</label><select name="type" class="input"><option value="receivables">Receivables</option><option value="payables">Payables</option></select></div>
                        <div><label class="label">Period</label><select name="period" class="input"><option value="current">Current year</option><option value="prior">Prior year</option></select></div>
                    </div>
                    <div><label class="label">Listing date</label><input type="date" name="as_at" value="{{ old('as_at', $engagement->period_end->toDateString()) }}" class="input" required>@error('as_at')<p class="error">{{ $message }}</p>@enderror</div>
                    <div><label class="label">FSLI it supports</label><select name="engagement_fsli_id" class="input"><option value="">Trade receivables / payables (default)</option>@foreach ($fslis as $f)<option value="{{ $f->id }}">{{ $f->code }} · {{ $f->name }}</option>@endforeach</select></div>
                    @include('ledger.partials.source')
                    <button class="btn-primary w-full">Upload and check columns</button>
                </div>
            </form>
        @endif
    </div>
</x-layouts.app>
