<?php

namespace Paymenter\Extensions\Others\AbsoluteHostingDomains\Services;

use InvalidArgumentException;

class DomainNameNormalizer
{
    public function normalise(
        string $input,
        array $availableTlds,
        string $selectedTld,
    ): array {
        $hostname = $this->hostname($input);

        if (str_starts_with($hostname, 'www.')) {
            $hostname = substr($hostname, 4);
        }

        $hostname = rtrim($hostname, '.');
        $tlds = $this->normaliseTlds($availableTlds);
        $selectedTld = $this->normaliseTld($selectedTld);

        foreach ($tlds as $tld) {
            $suffix = ltrim($tld, '.');

            if ($hostname === $suffix) {
                throw new InvalidArgumentException(
                    'A domain name is required before the extension.',
                );
            }

            if (str_ends_with($hostname, $tld)) {
                return [
                    'sld' => $this->validateSld(
                        substr($hostname, 0, -strlen($tld)),
                    ),
                    'tld' => $tld,
                ];
            }
        }

        if (str_contains($hostname, '.')) {
            throw new InvalidArgumentException(
                'The domain extension is not available.',
            );
        }

        return [
            'sld' => $this->validateSld($hostname),
            'tld' => $selectedTld,
        ];
    }

    private function hostname(string $input): string
    {
        $input = strtolower(trim($input));

        if ($input === '') {
            throw new InvalidArgumentException(
                'A domain name is required.',
            );
        }

        $candidate = str_contains($input, '://')
            ? $input
            : 'https://' . $input;

        $hostname = parse_url($candidate, PHP_URL_HOST);

        if (!is_string($hostname) || $hostname === '') {
            throw new InvalidArgumentException(
                'The domain name is invalid.',
            );
        }

        return strtolower($hostname);
    }

    private function normaliseTlds(array $availableTlds): array
    {
        $tlds = array_values(array_unique(array_map(
            fn (mixed $tld): string => $this->normaliseTld((string) $tld),
            $availableTlds,
        )));

        usort(
            $tlds,
            fn (string $left, string $right): int => strlen($right) <=> strlen($left),
        );

        return $tlds;
    }

    private function normaliseTld(string $tld): string
    {
        $tld = strtolower(trim($tld));

        if ($tld === '') {
            throw new InvalidArgumentException(
                'A domain extension is required.',
            );
        }

        return '.' . ltrim($tld, '.');
    }

    private function validateSld(string $sld): string
    {
        $sld = strtolower(trim($sld, ". \t\n\r\0\x0B"));

        if (
            $sld === ''
            || strlen($sld) > 63
            || preg_match(
                '/^(?:[a-z0-9]|[a-z0-9][a-z0-9-]{0,61}[a-z0-9])$/',
                $sld,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'The domain name is invalid.',
            );
        }

        return $sld;
    }
}
