<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Billions\BillionsApi;

$apiKey = getenv('BILLIONS_API_KEY') ?: '';
$invoiceId = getenv('BILLIONS_INVOICE_ID') ?: ($argv[1] ?? '');

if ($apiKey === '') {
    throw new RuntimeException('BILLIONS_API_KEY is required');
}
if ($invoiceId === '') {
    throw new RuntimeException('BILLIONS_INVOICE_ID or first CLI argument is required');
}

$invoice = (new BillionsApi($apiKey))->getInvoice($invoiceId);

echo json_encode($invoice, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo 'Payment URL: ' . $invoice['invoice_url'] . PHP_EOL;

