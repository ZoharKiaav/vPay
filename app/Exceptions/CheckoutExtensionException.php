<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class CheckoutExtensionException extends RuntimeException
{
    public function __construct(
        string $diagnosticMessage,
        public readonly string $customerMessage,
        public readonly string $field =
            'checkoutConfig.domain_sld',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $diagnosticMessage,
            previous: $previous,
        );
    }
}
