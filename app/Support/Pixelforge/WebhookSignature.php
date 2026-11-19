<?php

declare(strict_types=1);

namespace App\Support\Pixelforge;

use App\Support\Pixelforge\Enums\WebhookSignatureFailure;
use App\Support\Pixelforge\Exceptions\InvalidWebhookSignatureException;
use App\Support\Pixelforge\Exceptions\WebhookSecretNotConfiguredException;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;

/**
 * Pixelforge's webhook signature: `Pixelforge-Signature: t=<unix>,v1=<hex>`,
 * where the hex is the HMAC-SHA256 of `<t>.<raw body>` keyed with the
 * `whsec_…` secret. Verifying always runs on the exact bytes received, never
 * on re-encoded JSON. Signing serves the tests and PE-010's fake.
 */
final readonly class WebhookSignature
{
    public function __construct(
        #[Config('pixelforge.webhook_secret')]
        private ?string $secret,
        #[Config('pixelforge.webhook_tolerance_seconds')]
        private int $toleranceSeconds,
    ) {}

    /**
     * @return string the `Pixelforge-Signature` header value
     *
     * @throws WebhookSecretNotConfiguredException
     */
    public function sign(string $payload, int $timestamp): string
    {
        return sprintf('t=%d,v1=%s', $timestamp, $this->hmac($this->configuredSecret(), (string) $timestamp, $payload));
    }

    /**
     * The signature is checked before the timestamp's age, so a stale
     * rejection always means a genuine but old delivery.
     *
     * @throws WebhookSecretNotConfiguredException
     * @throws InvalidWebhookSignatureException
     */
    public function verify(string $payload, ?string $header): void
    {
        $secret = $this->configuredSecret();

        if ($header === null || trim($header) === '') {
            throw InvalidWebhookSignatureException::because(WebhookSignatureFailure::Missing);
        }

        [$timestamp, $signature] = $this->parse($header);

        if (! hash_equals($this->hmac($secret, $timestamp, $payload), $signature)) {
            throw InvalidWebhookSignatureException::because(WebhookSignatureFailure::Mismatch);
        }

        if (abs(CarbonImmutable::now()->getTimestamp() - (int) $timestamp) > $this->toleranceSeconds) {
            throw InvalidWebhookSignatureException::because(WebhookSignatureFailure::Stale);
        }
    }

    /**
     * @throws WebhookSecretNotConfiguredException
     */
    private function configuredSecret(): string
    {
        if ($this->secret === null || $this->secret === '') {
            throw WebhookSecretNotConfiguredException::missing();
        }

        return $this->secret;
    }

    /**
     * Comma-separated `key=value` parts, each trimmed. `t` must be digits that
     * fit an integer and `v1` must be present; either one repeated is refused,
     * because several signatures are not supported. Unknown keys are ignored.
     *
     * @return array{string, string} the timestamp as sent, and the signature
     *
     * @throws InvalidWebhookSignatureException
     */
    private function parse(string $header): array
    {
        $parts = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                throw InvalidWebhookSignatureException::because(WebhookSignatureFailure::Malformed);
            }

            [$key, $value] = $pair;

            if (in_array($key, ['t', 'v1'], true) && array_key_exists($key, $parts)) {
                throw InvalidWebhookSignatureException::because(WebhookSignatureFailure::Malformed);
            }

            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if (preg_match('/^\d{1,18}\z/', $timestamp) !== 1 || $signature === '') {
            throw InvalidWebhookSignatureException::because(WebhookSignatureFailure::Malformed);
        }

        return [$timestamp, $signature];
    }

    private function hmac(string $secret, string $timestamp, string $payload): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }
}
