<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Firm;
use App\Models\Invoice;
use App\Services\Billing\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class BillingAdminController extends Controller
{
    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), ['issued', 'paid', 'void'], true) ? $request->query('status') : null;

        return view('admin.invoices', [
            'invoices' => Invoice::with('firm')->when($status, fn ($q) => $q->where('status', $status))->latest('issued_at')->paginate(50)->withQueryString(),
            'status' => $status,
            'outstanding' => (int) Invoice::where('status', 'issued')->sum('total_sen'),
        ]);
    }

    public function show(Invoice $invoice): View
    {
        return view('firm.invoice', ['invoice' => $invoice->load('firm'), 'payment' => null, 'admin' => true]);
    }

    public function issue(Request $request, Firm $firm, InvoiceService $invoices): RedirectResponse
    {
        $sub = $firm->currentSubscription()->with('plan')->first();
        abort_unless($sub, 422, 'The firm has no subscription.');
        $inv = $invoices->issue($sub, Carbon::parse($sub->current_period_start)->startOfDay(), Carbon::parse($sub->current_period_end)->startOfDay()->subDay(), $request->user());

        return back()->with('status', "Invoice {$inv->number} issued.");
    }

    public function paid(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $d = $request->validate(['payment_reference' => ['required', 'string', 'max:120'], 'payment_method' => ['nullable', 'string', 'max:30']]);
        $invoices->markPaid($invoice, $d['payment_reference'], $d['payment_method'] ?? null, $request->user());

        return back()->with('status', "Invoice {$invoice->number} marked paid.");
    }

    public function void(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $invoices->void($invoice, $request->validate(['void_reason' => ['required', 'string', 'max:500']])['void_reason'], $request->user());

        return back()->with('status', "Invoice {$invoice->number} voided.");
    }
}
