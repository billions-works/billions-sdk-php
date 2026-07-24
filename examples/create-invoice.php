<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Billions\BillionsApi;

$apiKey = getenv('BILLIONS_API_KEY') ?: '';
if ($apiKey === '') {
    throw new RuntimeException('BILLIONS_API_KEY is required');
}

$api = new BillionsApi($apiKey);
$reference = 'example-' . time();
$invoice = $api->createInvoice([
    'currency' => 'USDT',
    'amount' => 100,
    'type' => 'b2b',
    'idempotencyKey' => $reference,
    'order_no' => $reference,
    'description' => 'Example invoice from billions-sdk-php',
    'callbackUrl' => 'https://example.com/webhook',
]);

echo json_encode($invoice, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo 'Payment URL: ' . $invoice['invoice_url'] . PHP_EOL;
