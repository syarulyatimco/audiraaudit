<?php

namespace App\Http\Controllers\Firm;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\Gateways\GatewayManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** A firm's own invoice (tenant-scoped by RLS and the firm scope). */
class InvoiceController extends Controller
{
    public function show(Invoice $invoice, GatewayManager $gateways): View
    {
        Gate::authorize('billing.view');

        return view('firm.invoice', ['invoice' => $invoice->load('firm'), 'payment' => $invoice->status === 'issued' ? $gateways->driver($invoice->gateway)->payment($invoice) : null, 'admin' => false]);
    }
}
