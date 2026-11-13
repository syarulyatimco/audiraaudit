<?php

namespace App\Http\Controllers\Firm;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Billing\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function __invoke(Request $request, BillingService $billing): View
    {
        Gate::authorize('billing.view');
        $firm = $request->user()->firm;

        return view('firm.billing', [
            'firm' => $firm,
            'estimate' => $billing->estimate($firm),
            'plans' => Plan::where('is_active', true)->orderBy('sort_order')->get(),
            'invoices' => \App\Models\Invoice::latest('issued_at')->limit(24)->get(),
        ]);
    }
}
