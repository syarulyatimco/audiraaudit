<x-layouts.app :title="'Invoice '.$invoice->number">
    @php $rm = fn ($v) => amount($v, false); @endphp
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="card">
            <div class="card-body space-y-6 text-sm">
                <div class="flex items-start justify-between gap-6">
                    <div><div class="text-2xl font-semibold text-navy-900">Invoice</div><div class="font-mono text-ink-600">{{ $invoice->number }}</div></div>
                    <div class="text-right"><div class="font-semibold text-navy-900">AUDIRA</div><div class="text-xs text-ink-500">Subscription services</div><x-status class="mt-2" :value="$invoice->isOverdue() ? 'past_due' : $invoice->status" /></div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><div class="label">Billed to</div><div class="font-medium">{{ $invoice->firm->name }}</div><div class="text-ink-500">{{ collect([$invoice->firm->address_line1, $invoice->firm->address_line2, trim($invoice->firm->postcode.' '.$invoice->firm->city), $invoice->firm->state])->filter()->join(', ') }}</div></div>
                    <dl class="text-right">
                        <div>Issued {{ local_time($invoice->issued_at, 'j M Y') }}</div><div>Due {{ $invoice->due_on->format('j M Y') }}</div>
                        <div>Period {{ $invoice->period_start->format('j M Y') }} – {{ $invoice->period_end->format('j M Y') }}</div>
                    </dl>
                </div>
                <table class="table">
                    <thead><tr><th>Description</th><th class="text-right">Qty</th><th class="text-right">Unit (RM)</th><th class="text-right">Amount (RM)</th></tr></thead>
                    <tbody>@foreach ($invoice->lines as $l)<tr><td>{{ $l['description'] }}</td><td class="text-right tabular-nums">{{ $l['quantity'] }}</td><td class="text-right tabular-nums">{{ $rm($l['unit_price_sen']) }}</td><td class="text-right tabular-nums">{{ $rm($l['amount_sen']) }}</td></tr>@endforeach</tbody>
                    <tfoot>
                        <tr><td colspan="3" class="px-4 py-2 text-right">Subtotal</td><td class="px-4 py-2 text-right tabular-nums">{{ $rm($invoice->subtotal_sen) }}</td></tr>
                        @if ($invoice->tax_name)<tr><td colspan="3" class="px-4 py-2 text-right">{{ $invoice->tax_name }} ({{ rtrim(rtrim((string) $invoice->tax_rate, '0'), '.') }}%)</td><td class="px-4 py-2 text-right tabular-nums">{{ $rm($invoice->tax_sen) }}</td></tr>@endif
                        <tr class="border-t-2 border-navy-100 font-semibold"><td colspan="3" class="px-4 py-2 text-right">Total ({{ $invoice->currency }})</td><td class="px-4 py-2 text-right tabular-nums">{{ $rm($invoice->total_sen) }}</td></tr>
                    </tfoot>
                </table>
                @if ($invoice->status === 'paid')<p class="text-emerald-800">Paid {{ local_time($invoice->paid_at) }} — reference {{ $invoice->payment_reference }}.</p>@endif
                @if ($invoice->status === 'void')<p class="text-ink-500">Void: {{ $invoice->void_reason }}</p>@endif
                @if ($payment)<div class="rounded-md bg-navy-50 px-4 py-3"><div class="label">How to pay</div>@if ($payment['type'] === 'redirect')<a href="{{ $payment['url'] }}" class="btn-accent">Pay online</a>@else<p class="text-ink-700">{{ $payment['text'] }}</p>@endif</div>@endif
            </div>
        </div>
        @if ($admin && $invoice->status === 'issued')
            <div class="grid gap-6 sm:grid-cols-2">
                <form method="POST" action="{{ route('admin.invoices.paid', $invoice) }}" class="card card-body space-y-3">@csrf
                    <x-field name="payment_reference" label="Payment reference" required />
                    <x-select name="payment_method" label="Method" :options="['bank_transfer' => 'Bank transfer', 'fpx' => 'FPX', 'card' => 'Card', 'other' => 'Other']" value="bank_transfer" />
                    <button class="btn-accent w-full">Record payment</button></form>
                <form method="POST" action="{{ route('admin.invoices.void', $invoice) }}" class="card card-body space-y-3" onsubmit="return confirm('Void this invoice?')">@csrf
                    <x-field name="void_reason" label="Reason for voiding" required />
                    <button class="btn-danger w-full">Void invoice</button></form>
            </div>
        @endif
        <a href="{{ $admin ? route('admin.invoices.index') : route('firm.billing') }}" class="text-sm text-ink-500 hover:text-navy-900">← Back</a>
    </div>
</x-layouts.app>
