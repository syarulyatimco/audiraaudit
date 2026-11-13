<?php

namespace App\Services\Billing\Gateways;

use App\Models\Invoice;

/**
 * A way for firms to pay invoices. Implementations must never store card data in AUDIRA; hosted
 * checkouts redirect to the provider and confirm payment by verified webhook.
 */
interface PaymentGateway
{
    public function name(): string;

    /**
     * How the firm pays this invoice.
     *
     * @return array{type: 'instructions'|'redirect', text?: string, url?: string}
     */
    public function payment(Invoice $invoice): array;
}
