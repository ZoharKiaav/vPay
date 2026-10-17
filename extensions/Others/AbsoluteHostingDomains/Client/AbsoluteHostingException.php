<?php

namespace Paymenter\Extensions\Others\AbsoluteHostingDomains\Client;

use RuntimeException;
use Throwable;

class AbsoluteHostingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly mixed $responseData = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
