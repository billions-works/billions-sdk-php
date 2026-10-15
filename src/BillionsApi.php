<?php

declare(strict_types=1);

namespace Billions;

use Billions\Exception\BillionsApiException;
use Billions\Exception\CallbackVerificationException;
use Billions\Exception\InvoiceNotPaidException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use JsonException;
use Mmccook\JsonCanonicalizator\JsonCanonicalizatorFactory;

final class BillionsApi
{
    public readonly string $apiKey;
    public readonly string $baseUrl;
    public readonly string $version;
    public readonly ?string $webhookSecret;
    public readonly ?string $secretKey;

    private readonly ClientInterface $httpClient;

    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        ?string $version = null,
        ?ClientInterface $httpClient = null,
        ?string $webhookSecret = null,
        ?string $secretKey = null,
    ) {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new InvalidArgumentException('apiKey is required');
        }
        if ($webhookSecret === '') {
            throw new InvalidArgumentException('webhookSecret must not be empty');
        }
        if ($secretKey === '') {
            throw new InvalidArgumentException('secretKey must not be empty');
        }
        if ($webhookSecret !== null && $secretKey !== null && $webhookSecret !== $secretKey) {
            throw new InvalidArgumentException('secretKey and webhookSecret must match when both are provided');
        }

        $rawBaseUrl = trim($baseUrl ?? '');
        $normalizedBaseUrl = rtrim(
            $rawBaseUrl !== '' ? $rawBaseUrl : Constants::DEFAULT_BASE_URL,
            '/',
        );
        $normalizedVersion = trim($version ?? Constants::PUBLIC_API_VERSION);

        $this->apiKey = $apiKey;
        $this->baseUrl = $normalizedBaseUrl;
        $this->version = $normalizedVersion !== '' ? $normalizedVersion : Constants::PUBLIC_API_VERSION;
        $this->secretKey = $secretKey ?? $webhookSecret;
        $this->webhookSecret = $this->secretKey;
        $this->httpClient = $httpClient ?? new Client();
    }

    public function getVersion(): string
    {
        /** @var string */
        return $this->request('GET', '/version');
    }

    /**
     * @return list<array{
     *   ticker: string,
     *   fullName: string,
     *   network: string,
     *   contractAddress: string|null,
     *   type: 'stablecoin'|'fiat'|'token',
     *   icon: string,
     *   iconDark: string,
     *   internalTicker: string
     * }>
     */
    public function getCurrencies(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->request('GET', '/currencies');
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function getCountries(): array
    {
        /** @var list<array{code: string, name: string}> */
        return $this->request('GET', '/countries');
    }

    /**
     * @return array{
     *   id: string,
     *   email: string,
     *   merchantName: string|null,
     *   directorName: string|null,
     *   website: string|null,
     *   logo: string|null,
     *   whitelabeling: bool
     * }
     */
    public function getMerchantInfo(): array
    {
        /** @var array<string, mixed> */
        return $this->request('GET', '/me');
    }

    /**
     * @param array{
     *   currency: string,
     *   amount: int|float|string,
     *   type?: 'b2b'|'b2c',
     *   order_no?: string,
     *   description?: string,
     *   autoconvert?: string
     * } $payload
     * @return array{
     *   amount: int|float,
     *   finalPrice: int|float,
     *   fee: int|float,
     *   merchantReceiveAmount: int|float,
     *   summaryFee?: int|float
     * }
     */
    public function estimateFees(array $payload): array
    {
        /** @var array<string, int|float> */
        return $this->request('POST', '/invoices/estimateFees', $payload);
    }

    /**
     * @param array{
     *   currency: string,
     *   amount: int|float|string,
     *   type?: 'b2b'|'b2c',
     *   idempotencyKey?: string,
     *   order_no?: string,
     *   description?: string,
     *   autoconvert?: string,
     *   callbackUrl?: string,
     *   expiresAt?: string
     * } $payload
     * @return array<string, mixed> Includes network_and_other_fees.
     */
    public function createInvoice(array $payload): array
    {
        /** @var array<string, mixed> */
        return $this->request('POST', '/invoices/create', $payload);
    }

    /** @return array{request: array<string, mixed>} */
    public function createWithdrawal(array $payload, ?string $requestId = null, int|string|null $timestamp = null): array
    {
        return $this->signedMutation('/withdrawals/create', $payload, $requestId, $timestamp);
    }

    /** @return array{request: array<string, mixed>} */
    public function refundCrypto(array $payload, ?string $requestId = null, int|string|null $timestamp = null): array
    {
        return $this->signedMutation('/refunds/refundCrypto', $payload, $requestId, $timestamp);
    }

    /** @return array{request: array<string, mixed>} */
    public function refundFiat(array $payload, ?string $requestId = null, int|string|null $timestamp = null): array
    {
        return $this->signedMutation('/refunds/refundFiat', $payload, $requestId, $timestamp);
    }

    /** @return array<string, mixed> */
    public function getInvoice(string $invoiceId): array
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') {
            throw new InvalidArgumentException('invoiceId is required');
        }

        /** @var array<string, mixed> */
        return $this->request('GET', '/invoices/' . rawurlencode($invoiceId));
    }

    /**
     * Returns every invoice belonging to the current merchant with the given order number.
     *
     * @return list<array<string, mixed>>
     */
    public function getInvoicesByOrderNo(string $orderNo): array
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            throw new InvalidArgumentException('order_no is required');
        }

        /** @var list<array<string, mixed>> */
        return $this->request('GET', '/invoices/byOrder/' . rawurlencode($orderNo));
    }

    /**
     * Supports both TS-compatible forms:
     *   verifyInvoicePaid('invoice-id', $callback)
     *   verifyInvoicePaid($callbackWithIdOrWebhookEnvelope)
     *
     * @param string|array<string, mixed> $invoiceIdOrCallback
     * @param array<string, mixed>|null $callbackData
     * @return array<string, mixed>
     */
    public function verifyInvoicePaid(string|array $invoiceIdOrCallback, ?array $callbackData = null): array
    {
        if (is_string($invoiceIdOrCallback)) {
            $invoiceId = trim($invoiceIdOrCallback);
            $rawCallback = $callbackData;
        } else {
            $invoiceId = self::extractWebhookInvoiceId($invoiceIdOrCallback);
            $rawCallback = $invoiceIdOrCallback;
        }

        if ($invoiceId === '') {
            throw new InvalidArgumentException('invoice id is required');
        }
        if ($rawCallback === null) {
            throw new InvalidArgumentException('callback data is required');
        }
        $callback = self::extractWebhookData($rawCallback);

        $invoice = $this->getInvoice($invoiceId);

        if (($invoice['status'] ?? null) !== 'completed') {
            throw new InvoiceNotPaidException();
        }

        if (trim((string) ($callback['status'] ?? '')) !== trim((string) ($invoice['status'] ?? ''))) {
            throw new CallbackVerificationException('Callback status does not match API invoice status');
        }

        if (
            self::normalizeDecimalString($callback['paid_amount'] ?? '__missing_callback_paid_amount__')
            !== self::normalizeDecimalString($invoice['paid_amount'] ?? '')
        ) {
            throw new CallbackVerificationException('Callback paid_amount does not match API invoice paid_amount');
        }

        return $invoice;
    }

    /**
     * Retrieves authoritative invoice state for a current webhook envelope or legacy callback.
     *
     * @param array<string, mixed> $webhook
     * @return array<string, mixed>
     */
    public function getInvoiceFromWebhook(array $webhook): array
    {
        $invoiceId = self::extractWebhookInvoiceId($webhook);
        if ($invoiceId === '') {
            throw new InvalidArgumentException('invoice id is required');
        }

        return $this->getInvoice($invoiceId);
    }

    public function verifyWebhookSignature(string $signature, string $body): bool
    {
        if ($this->webhookSecret === null) {
            throw new InvalidArgumentException('webhookSecret is required to verify webhook signatures');
        }

        $normalizedSignature = preg_replace('/^jcs-sha256=/i', '', trim($signature));
        if (!is_string($normalizedSignature) || preg_match('/^[a-f0-9]{64}$/i', $normalizedSignature) !== 1) {
            return false;
        }

        try {
            $decodedBody = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        $canonicalBody = JsonCanonicalizatorFactory::getInstance()->canonicalize($decodedBody);
        $expectedSignature = hash_hmac('sha256', $canonicalBody, $this->webhookSecret);
        return hash_equals($expectedSignature, strtolower($normalizedSignature));
    }

    private function buildUrl(string $path): string
    {
        return sprintf('%s/public/%s%s', $this->baseUrl, $this->version, $path);
    }

    private function request(string $method, string $path, ?array $payload = null, array $headers = []): mixed
    {
        $options = [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->apiKey,
                ...$headers,
            ],
            'http_errors' => false,
        ];

        if ($payload !== null) {
            $options['json'] = $payload;
        }

        try {
            $response = $this->httpClient->request($method, $this->buildUrl($path), $options);
        } catch (GuzzleException $exception) {
            throw new BillionsApiException($exception->getMessage(), 0, null, $exception);
        }

        $statusCode = $response->getStatusCode();
        $responseText = (string) $response->getBody();
        $responseBody = $this->safeJsonDecode($responseText);

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = is_array($responseBody)
                && isset($responseBody['message'])
                && is_string($responseBody['message'])
                && trim($responseBody['message']) !== ''
                    ? $responseBody['message']
                    : "Billions API request failed with status {$statusCode}";

            throw new BillionsApiException($message, $statusCode, $responseBody);
        }

        return $responseBody;
    }

    /** @return array{request: array<string, mixed>} */
    private function signedMutation(
        string $path,
        array $payload,
        ?string $requestId,
        int|string|null $timestamp,
    ): array {
        if ($this->secretKey === null) {
            throw new InvalidArgumentException('secretKey is required for signed operations');
        }
        $timestamp = (string) ($timestamp ?? time());
        $requestId = strtolower(trim($requestId ?? self::uuidV4()));
        $canonicalBody = JsonCanonicalizatorFactory::getInstance()->canonicalize((object) $payload);
        $message = "POST\n/public/{$this->version}{$path}\n{$timestamp}\n{$requestId}\n{$canonicalBody}";
        $signature = hash_hmac('sha256', $message, $this->secretKey);

        /** @var array{request: array<string, mixed>} */
        return $this->request('POST', $path, $payload, [
            'x-billions-timestamp' => $timestamp,
            'x-billions-request-id' => $requestId,
            'x-billions-request-signature' => "v1={$signature}",
        ]);
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    private function safeJsonDecode(string $value): mixed
    {
        if ($value === '') {
            return null;
        }

        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $value;
        }
    }

    private static function normalizeDecimalString(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '0';
        }
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $raw) !== 1) {
            return $raw;
        }

        $negative = str_starts_with($raw, '-');
        $unsigned = $negative ? substr($raw, 1) : $raw;
        [$integerPart, $fractionPart] = array_pad(explode('.', $unsigned, 2), 2, '');
        $integerPart = preg_replace('/^0+(?=\d)/', '', $integerPart) ?: '0';
        $fractionPart = rtrim($fractionPart, '0');
        $normalized = $fractionPart !== '' ? "{$integerPart}.{$fractionPart}" : $integerPart;

        return $negative && $normalized !== '0' ? "-{$normalized}" : $normalized;
    }

    /** @param array<string, mixed> $webhook */
    private static function extractWebhookInvoiceId(array $webhook): string
    {
        $data = self::extractWebhookData($webhook);
        $invoiceId = trim((string) ($data['invoice_id'] ?? ''));
        if ($invoiceId !== '') {
            return $invoiceId;
        }

        $invoiceId = trim((string) ($webhook['invoice_id'] ?? ''));
        return $invoiceId !== '' ? $invoiceId : trim((string) ($webhook['id'] ?? ''));
    }

    /**
     * @param array<string, mixed> $webhook
     * @return array<string, mixed>
     */
    private static function extractWebhookData(array $webhook): array
    {
        return isset($webhook['data']) && is_array($webhook['data'])
            ? $webhook['data']
            : $webhook;
    }
}
