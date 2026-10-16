<?php

namespace Paymenter\Extensions\Others\AbsoluteHostingDomains\Services;

use InvalidArgumentException;
use Stringable;

class CustomerPropertyExtractor
{
    private const ALLOWED_KEYS = [
        'phone',
        'company_name',
        'country',
        'address',
        'address2',
        'city',
        'state',
        'zip',
        'tax_id',
    ];

    public function extract(iterable $properties): array
    {
        $extracted = [];

        foreach ($properties as $property) {
            $key = $this->propertyKey($property);

            if ($key === null || !in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }

            if (array_key_exists($key, $extracted)) {
                throw new InvalidArgumentException(
                    "Duplicate customer property detected: {$key}.",
                );
            }

            $extracted[$key] = $this->propertyValue(
                data_get($property, 'value'),
                $key,
            );
        }

        return $extracted;
    }

    private function propertyKey(mixed $property): ?string
    {
        $key = data_get($property, 'parent_property.key');

        if (!is_string($key)) {
            return null;
        }

        $key = trim($key);

        return $key === '' ? null : $key;
    }

    private function propertyValue(
        mixed $value,
        string $key,
    ): string {
        if ($value === null) {
            return '';
        }

        if (
            !is_scalar($value)
            && !$value instanceof Stringable
        ) {
            throw new InvalidArgumentException(
                "Customer property {$key} must contain a scalar value.",
            );
        }

        return trim((string) $value);
    }
}
