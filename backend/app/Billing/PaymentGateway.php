<?php
declare(strict_types=1);

namespace LeenTrack\Billing;

/** The payment provider as the billing service sees it. Paystack today; Flutterwave could implement the same interface (docs G-09). */
interface PaymentGateway
{
    public function isConfigured(): bool;

    /**
     * Start a transaction for an amount the SERVER chose.
     * @param array{email:string,amount_minor:int,currency:string,reference:string,callback_url:?string,metadata:array<string,string>} $tx
     * @return array{authorization_url:string,access_code:string,reference:string}
     * @throws \LeenTrack\Core\ApiException 502 payment_provider_error / 503 billing_not_configured
     */
    public function initialize(array $tx): array;

    /**
     * Ask the provider what really happened to a reference.
     * @return array{status:string,reference:string,amount_minor:int,currency:string,transaction_id:?string,paid_at:?string}
     * @throws \LeenTrack\Core\ApiException
     */
    public function verify(string $reference): array;

    /** Constant-time check of the provider's webhook signature over the RAW body. */
    public function signatureValid(string $rawBody, ?string $signature): bool;
}
