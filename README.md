# billions-sdk-php

PHP SDK for the Billions public API. Requires PHP 8.1 or newer.

## Installation

```bash
composer require billions-works/sdk-php
```

## Usage

```php
<?php

require 'vendor/autoload.php';

use Billions\BillionsApi;

$api = new BillionsApi('your-api-key');

$version = $api->getVersion();
$currencies = $api->getCurrencies();
$invoice = $api->createInvoice([
    'currency' => 'USDT',
    'amount' => 100,
    'idempotencyKey' => 'checkout-attempt-123',
    'order_no' => 'ORDER-123',
    'callbackUrl' => 'https://example.com/webhook',
]);

echo $invoice['invoice_url'];
```

Default API host: `https://api.billions.works`

Default public API version: `v1`

The constructor also accepts a custom host, API version, and Guzzle client:

```php
$api = new BillionsApi(
    apiKey: 'your-api-key',
    baseUrl: 'http://localhost:3000',
    version: 'v1',
    httpClient: $customGuzzleClient,
);
```

## Available methods

```php
$api->getVersion();
$api->getCurrencies();
$api->getMerchantInfo();
$api->estimateFees($payload);
$api->createInvoice($payload);
$api->getInvoice($invoiceId);
$api->getInvoicesByOrderNo($orderNo);
$api->verifyInvoicePaid($invoiceId, $callbackData);
$api->verifyInvoicePaid($callbackDataWithId);
```

`createInvoice()` accepts an optional `idempotencyKey`. Repeating the request with
the same key returns the invoice already associated with that key. Use `order_no`
for your business order reference and `getInvoicesByOrderNo()` to recover invoices
for that order.

Invoice responses include `amount`, `final_amount`, `merchant_fee`,
`merchant_receive_amount`, and `paid_amount`.

API objects are returned as associative arrays so their field names remain identical
to the TypeScript SDK and API JSON.

## Callback verification

Always retrieve the invoice from the API before trusting a payment callback:

```php
use Billions\Exception\CallbackVerificationException;
use Billions\Exception\InvoiceNotPaidException;

try {
    $verifiedInvoice = $api->verifyInvoicePaid([
        'id' => $_POST['id'],
        'status' => $_POST['status'],
        'paid_amount' => $_POST['paid_amount'],
    ]);
} catch (InvoiceNotPaidException | CallbackVerificationException $exception) {
    // Reject or postpone callback processing.
}
```

The method checks that the API invoice is `completed`, then compares its status and
paid amount with the callback. Decimal strings are normalized before comparison,
so `100`, `100.0`, and `100.000000000000000000` are equivalent.

## Errors

- `Billions\Exception\BillionsApiException` exposes `statusCode` and `responseBody`.
- `Billions\Exception\InvoiceNotPaidException` means the API invoice is not completed.
- `Billions\Exception\CallbackVerificationException` means callback data differs from API data.

## Development

```bash
composer install
composer test
```
