<?php

namespace Paymenter\Extensions\Others\AbsoluteHostingDomains\Client;

use App\Exceptions\CheckoutExtensionException;
use Throwable;

class AbsoluteHostingException extends CheckoutExtensionException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly mixed $responseData = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            diagnosticMessage: $message,
            customerMessage: 'The domain service is temporarily unavailable. Please try again.',
            previous: $previous,
        );
    }
}
