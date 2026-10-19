<?php

namespace Paymenter\Extensions\Others\AbsoluteHostingDomains\Services;

use InvalidArgumentException;

class DomainRetailPriceService
{
    public function calculate(
        float|int|string $providerPrice,
        int|string $period,
        array $settings = [],
    ): float {
        $providerPrice = $this->nonNegativeNumber(
            $providerPrice,
            'The provider price must be a non-negative number.',
        );

        $period = filter_var(
            $period,
            FILTER_VALIDATE_INT,
        );

        if ($period === false || $period < 1) {
            throw new InvalidArgumentException(
                'The registration period must be a positive integer.',
            );
        }

        $percentageMarkup = $this->nonNegativeNumber(
            $settings['markup_percentage'] ?? 0,
            'The percentage markup must be a non-negative number.',
        );

        $fixedMarkup = $this->nonNegativeNumber(
            $settings['markup_fixed'] ?? 0,
            'The fixed markup must be a non-negative number.',
        );

        $annualRetailPrice = (
            $providerPrice * (1 + ($percentageMarkup / 100))
        ) + $fixedMarkup;

        return round(
            $annualRetailPrice * $period,
            2,
        );
    }

    private function nonNegativeNumber(
        mixed $value,
        string $message,
    ): float {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($message);
        }

        $value = (float) $value;

        if (!is_finite($value) || $value < 0) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }
}
