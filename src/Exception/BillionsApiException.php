<?php

declare(strict_types=1);

namespace Billions\Exception;

use Throwable;

class BillionsApiException extends BillionsSdkException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly mixed $responseBody,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}

