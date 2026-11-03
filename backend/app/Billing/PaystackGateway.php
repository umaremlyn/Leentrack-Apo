<?php
declare(strict_types=1);

namespace LeenTrack\Billing;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Logger;

/** Paystack REST client (curl). The secret key never leaves this class: it is not logged, returned or put in error messages. */
final class PaystackGateway implements PaymentGateway
{
    public function __construct(private readonly string $secret, private readonly string $baseUrl, private readonly Logger $log, private readonly int $timeout = 15)
    {
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        if (!str_starts_with($baseUrl, 'https://') && !in_array($host, ['127.0.0.1', 'localhost'], true)) {
            throw new \InvalidArgumentException('PAYSTACK_BASE_URL must be https (plain http is accepted only for localhost, in tests).');   // the secret key is sent in a header
        }
    }

    public function isConfigured(): bool
    {
        return $this->secret !== '';
    }

    public function initialize(array $tx): array
    {
        $r = $this->call('POST', '/transaction/initialize', [
            'email' => $tx['email'], 'amount' => $tx['amount_minor'], 'currency' => $tx['currency'], 'reference' => $tx['reference'],
            'callback_url' => $tx['callback_url'], 'metadata' => $tx['metadata'],
        ]);
        $d = $r['data'] ?? null;
        if (!is_array($d) || !is_string($d['authorization_url'] ?? null) || !is_string($d['access_code'] ?? null) || ($d['reference'] ?? null) !== $tx['reference']) {
            $this->log->error('paystack initialize: unexpected response');
            throw new ApiException('payment_provider_error', 'The payment provider returned an unexpected answer. Please try again.', 502);
        }
        if (!str_starts_with($d['authorization_url'], 'https://')) { throw new ApiException('payment_provider_error', 'The payment provider returned an unexpected answer.', 502); }
        return ['authorization_url' => $d['authorization_url'], 'access_code' => $d['access_code'], 'reference' => $d['reference']];
    }

    public function verify(string $reference): array
    {
        $r = $this->call('GET', '/transaction/verify/' . rawurlencode($reference), null);
        $d = $r['data'] ?? null;
        if (!is_array($d) || !is_string($d['status'] ?? null) || !is_int($d['amount'] ?? null) || !is_string($d['currency'] ?? null) || !is_string($d['reference'] ?? null)) {
            $this->log->error('paystack verify: unexpected response');
            throw new ApiException('payment_provider_error', 'The payment provider returned an unexpected answer. Please try again.', 502);
        }
        return ['status' => $d['status'], 'reference' => $d['reference'], 'amount_minor' => $d['amount'], 'currency' => strtoupper($d['currency']),
            'transaction_id' => isset($d['id']) ? (string) $d['id'] : null, 'paid_at' => is_string($d['paid_at'] ?? null) ? $d['paid_at'] : null];
    }

    public function signatureValid(string $rawBody, ?string $signature): bool
    {
        if ($this->secret === '' || $signature === null || $signature === '') { return false; }
        return hash_equals(hash_hmac('sha512', $rawBody, $this->secret), strtolower($signature));
    }

    /** @param array<string,mixed>|null $body @return array<string,mixed> */
    private function call(string $method, string $path, ?array $body): array
    {
        if ($this->secret === '') { throw new ApiException('billing_not_configured', 'Online payments are not set up yet.', 503); }
        $ch = curl_init(rtrim($this->baseUrl, '/') . $path);
        $headers = ['Authorization: Bearer ' . $this->secret, 'Accept: application/json'];
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $this->timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($out === false || $err !== 0) {
            $this->log->error('paystack request failed', ['path' => preg_replace('~/verify/.*~', '/verify/…', $path), 'curl_errno' => $err]);
            throw new ApiException('payment_provider_unreachable', 'Could not reach the payment provider. Nothing was charged; please try again.', 502);
        }
        $j = json_decode((string) $out, true);
        if ($status < 200 || $status >= 300 || !is_array($j) || ($j['status'] ?? false) !== true) {
            $this->log->error('paystack request rejected', ['path' => preg_replace('~/verify/.*~', '/verify/…', $path), 'http' => $status]);
            throw new ApiException('payment_provider_error', 'The payment provider could not process this request.', 502);
        }
        return $j;
    }
}
