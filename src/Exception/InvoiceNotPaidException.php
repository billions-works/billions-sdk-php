<?php

declare(strict_types=1);

namespace Billions\Exception;

class InvoiceNotPaidException extends BillionsSdkException
{
    public function __construct(string $message = 'Invoice is not paid yet')
    {
        parent::__construct($message);
    }
}

