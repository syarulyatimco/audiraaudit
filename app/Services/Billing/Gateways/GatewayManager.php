<?php

namespace App\Services\Billing\Gateways;

use InvalidArgumentException;

class GatewayManager
{
    /** @var array<string, class-string<PaymentGateway>> */
    private const DRIVERS = ['manual' => ManualGateway::class];

    public function driver(?string $name = null): PaymentGateway
    {
        $name ??= (string) config('audira.billing.gateway', 'manual');
        $class = self::DRIVERS[$name] ?? throw new InvalidArgumentException("Payment gateway [{$name}] is not available. Configure AUDIRA_BILLING_GATEWAY.");

        return app($class);
    }
}
