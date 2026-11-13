<?php

namespace App\Services\Billing\Gateways;

use App\Models\Invoice;

/** Bank transfer: the firm pays offline quoting the invoice number; platform staff record the payment. */
class ManualGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'manual';
    }

    public function payment(Invoice $invoice): array
    {
        return ['type' => 'instructions', 'text' => str_replace('{number}', $invoice->number, (string) config('audira.billing.manual.instructions'))];
    }
}
