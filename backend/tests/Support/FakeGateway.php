<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Support;

use LeenTrack\Billing\PaymentGateway;
use LeenTrack\Core\ApiException;

/** Stand-in for Paystack: remembers what was initialised, answers verify() from what the TEST says the customer did, signs like the real thing. */
final class FakeGateway implements PaymentGateway
{
    public const SECRET = 'sk_test_fake_secret';
    /** @var array<string,array<string,mixed>> reference => initialised transaction */
    public array $initialised = [];
    /** @var array<string,array<string,mixed>> reference => what the provider "says" (overrides) */
    public array $remote = [];
    public bool $configured = true;
    public bool $failInit = false;
    public bool $failVerify = false;
    public int $verifyCalls = 0;

    public function isConfigured(): bool { return $this->configured; }

    public function initialize(array $tx): array
    {
        if ($this->failInit) { throw new ApiException('payment_provider_unreachable', 'down', 502); }
        $this->initialised[$tx['reference']] = $tx;
        return ['authorization_url' => 'https://checkout.paystack.test/' . $tx['reference'], 'access_code' => 'ac_' . substr($tx['reference'], 3), 'reference' => $tx['reference']];
    }

    /** the customer paid exactly what was asked (default) unless the test overrides fields */
    public function pay(string $reference, array $override = []): void
    {
        $tx = $this->initialised[$reference];
        $this->remote[$reference] = $override + ['status' => 'success', 'reference' => $reference, 'amount_minor' => $tx['amount_minor'], 'currency' => $tx['currency'], 'transaction_id' => '9' . random_int(1000000, 9999999), 'paid_at' => gmdate('c')];
    }

    public function verify(string $reference): array
    {
        $this->verifyCalls++;
        if ($this->failVerify) { throw new ApiException('payment_provider_unreachable', 'down', 502); }
        $tx = $this->initialised[$reference] ?? null;
        return $this->remote[$reference] ?? ['status' => 'pending', 'reference' => $reference, 'amount_minor' => (int) ($tx['amount_minor'] ?? 0), 'currency' => (string) ($tx['currency'] ?? 'NGN'), 'transaction_id' => null, 'paid_at' => null];
    }

    public function signatureValid(string $rawBody, ?string $signature): bool
    {
        return $signature !== null && $signature !== '' && hash_equals(hash_hmac('sha512', $rawBody, self::SECRET), strtolower($signature));
    }

    public static function sign(string $raw): string { return hash_hmac('sha512', $raw, self::SECRET); }
}
