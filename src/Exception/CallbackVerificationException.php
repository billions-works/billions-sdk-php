<?php

declare(strict_types=1);

namespace Billions\Exception;

class CallbackVerificationException extends BillionsSdkException
{
    public function __construct(string $message = 'Callback data does not match invoice data from API')
    {
        parent::__construct($message);
    }
}

