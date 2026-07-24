<?php

declare(strict_types=1);

namespace Billions\Tests;

use Billions\BillionsApi;
use Billions\Exception\BillionsApiException;
use Billions\Exception\CallbackVerificationException;
use Billions\Exception\InvoiceNotPaidException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class BillionsApiTest extends TestCase
{
    public function testConstructorNormalizesOptions(): void
    {
        $api = new BillionsApi('  secret  ', 'https://example.test///', '');

        self::assertSame('secret', $api->apiKey);
        self::assertSame('https://example.test', $api->baseUrl);
        self::assertSame('v1', $api->version);
    }

    public function testBlankBaseUrlFallsBackToDefault(): void
    {
        $api = new BillionsApi('secret', ' ');

        self::assertSame('https://api.billions.works', $api->baseUrl);
    }

    public function testApiKeyIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('apiKey is required');

        new BillionsApi(' ');
    }

    public function testGetVersionUsesExpectedUrlAndHeaders(): void
    {
        $history = [];
        $api = $this->apiWithResponses(
            [new Response(200, [], '"v1"')],
            $history,
        );

        self::assertSame('v1', $api->getVersion());
        self::assertCount(1, $history);

        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://example.test/public/v1/version', (string) $request->getUri());
        self::assertSame('secret', $request->getHeaderLine('x-api-key'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testCreateInvoiceSendsJsonAndReturnsDecodedArray(): void
    {
        $history = [];
        $invoice = $this->completedInvoice();
        $api = $this->apiWithResponses(
            [new Response(200, [], json_encode($invoice, JSON_THROW_ON_ERROR))],
            $history,
        );

        $result = $api->createInvoice([
            'currency' => 'USDT',
            'amount' => 100,
            'idempotencyKey' => 'attempt-42',
            'order_no' => 'ORDER-42',
            'callbackUrl' => 'https://example.com/webhook',
        ]);

        self::assertSame($invoice, $result);
        self::assertSame('POST', $history[0]['request']->getMethod());
        self::assertSame(
            [
                'currency' => 'USDT',
                'amount' => 100,
                'idempotencyKey' => 'attempt-42',
                'order_no' => 'ORDER-42',
                'callbackUrl' => 'https://example.com/webhook',
            ],
            json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testGetInvoiceEncodesId(): void
    {
        $history = [];
        $api = $this->apiWithResponses([new Response(200, [], '{}')], $history);

        $api->getInvoice(' invoice/42 ');

        self::assertSame(
            'https://example.test/public/v1/invoices/invoice%2F42',
            (string) $history[0]['request']->getUri(),
        );
    }

    public function testGetInvoicesByOrderNoEncodesOrderNumber(): void
    {
        $history = [];
        $api = $this->apiWithResponses([new Response(200, [], '[]')], $history);

        self::assertSame([], $api->getInvoicesByOrderNo(' ORDER/42 '));
        self::assertSame(
            'https://example.test/public/v1/invoices/byOrder/ORDER%2F42',
            (string) $history[0]['request']->getUri(),
        );
    }

    public function testGetInvoicesByOrderNoRequiresValue(): void
    {
        $api = $this->apiWithResponses([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('order_no is required');
        $api->getInvoicesByOrderNo(' ');
    }

    public function testApiErrorExposesStatusAndResponseBody(): void
    {
        $api = $this->apiWithResponses(
            [new Response(422, [], '{"message":"Invalid currency","field":"currency"}')],
        );

        try {
            $api->getCurrencies();
            self::fail('Expected BillionsApiException');
        } catch (BillionsApiException $exception) {
            self::assertSame('Invalid currency', $exception->getMessage());
            self::assertSame(422, $exception->statusCode);
            self::assertSame(
                ['message' => 'Invalid currency', 'field' => 'currency'],
                $exception->responseBody,
            );
        }
    }

    public function testVerifyInvoicePaidAcceptsEquivalentDecimalFormats(): void
    {
        $invoice = $this->completedInvoice();
        $api = $this->apiWithResponses(
            [new Response(200, [], json_encode($invoice, JSON_THROW_ON_ERROR))],
        );

        $result = $api->verifyInvoicePaid([
            'id' => 'invoice-id',
            'status' => 'completed',
            'paid_amount' => 100,
        ]);

        self::assertSame($invoice, $result);
    }

    public function testVerifyInvoicePaidRejectsUnpaidInvoice(): void
    {
        $invoice = $this->completedInvoice();
        $invoice['status'] = 'pending';
        $api = $this->apiWithResponses(
            [new Response(200, [], json_encode($invoice, JSON_THROW_ON_ERROR))],
        );

        $this->expectException(InvoiceNotPaidException::class);
        $api->verifyInvoicePaid('invoice-id', [
            'status' => 'pending',
            'paid_amount' => '0',
        ]);
    }

    public function testVerifyInvoicePaidRejectsMismatchedAmount(): void
    {
        $api = $this->apiWithResponses(
            [new Response(200, [], json_encode($this->completedInvoice(), JSON_THROW_ON_ERROR))],
        );

        $this->expectException(CallbackVerificationException::class);
        $this->expectExceptionMessage('Callback paid_amount does not match');
        $api->verifyInvoicePaid('invoice-id', [
            'status' => 'completed',
            'paid_amount' => '99',
        ]);
    }

    /**
     * @param list<Response> $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function apiWithResponses(array $responses, array &$history = []): BillionsApi
    {
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $handler->push(Middleware::history($history));
        $client = new Client(['handler' => $handler]);

        return new BillionsApi('secret', 'https://example.test', 'v1', $client);
    }

    /** @return array<string, mixed> */
    private function completedInvoice(): array
    {
        return [
            'created_at' => '2026-06-24T09:50:15.555Z',
            'updated_at' => '2026-06-24T09:50:15.555Z',
            'expires_at' => null,
            'invoice_url' => 'https://pay.example.test/invoice-id',
            'id' => 'invoice-id',
            'type' => 'b2b',
            'status' => 'completed',
            'merchant_id' => 'merchant-id',
            'settlement_currency' => 'USDT',
            'currency' => 'USDT_TRON',
            'convert_currency' => null,
            'amount' => '101.000000000000000000',
            'final_amount' => '101.000000000000000000',
            'merchant_fee' => '1.000000000000000000',
            'merchant_receive_amount' => '100.000000000000000000',
            'paid_amount' => '100.000000000000000000',
            'callback_url' => 'https://example.com/webhook',
            'order_no' => '123321',
            'description' => 'Test invoice',
        ];
    }
}
