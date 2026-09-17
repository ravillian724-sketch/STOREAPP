<?php

namespace App\Contracts\Payment;

use App\Support\Payment\VerifiedPaymentWebhook;

interface PaymentProviderWebhookVerifier
{
    public function providerCode(): string;

    /**
     * Implementations MUST authenticate the exact raw body
     * using the provider's signature scheme before returning.
     *
     * Invalid or unauthenticated input must throw.
     */
    public function verify(
        string $rawBody,
        array $headers,
    ): VerifiedPaymentWebhook;
}
