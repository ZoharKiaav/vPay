<?php

namespace Paymenter\Extensions\Others\AbsoluteHostingDomains\Services;

use Carbon\Carbon;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingException;
use Throwable;

class DomainReconciliationService
{
    public function normalise(mixed $response): DomainReconciliationResult
    {
        if (!is_array($response)) {
            throw new AbsoluteHostingException(
                'Absolute Hosting returned invalid domain information.',
                responseData: $response,
            );
        }

        return new DomainReconciliationResult(
            domain: $this->domain($response),
            status: $this->requiredString(
                $response,
                'status',
                'Absolute Hosting domain information is missing status.',
            ),
            expiryDate: $this->expiryDate($response),
            nameservers: $this->nameservers($response),
            transferLocked: $this->optionalBoolean(
                $response,
                'transferLock',
            ),
            restorable: $this->optionalBoolean(
                $response,
                'restorable',
            ),
            pendingSuspension: $this->optionalBoolean(
                $response,
                'willDomainSuspend',
            ),
            renewBeforeExpiration: $this->optionalBoolean(
                $response,
                'renewBeforeExpiration',
            ),
        );
    }

    private function domain(array $response): string
    {
        $domain = strtolower(rtrim(
            $this->requiredString(
                $response,
                'domain',
                'Absolute Hosting domain information is missing domain.',
            ),
            '.',
        ));

        if (
            !str_contains($domain, '.')
            || preg_match(
                '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
                $domain,
            ) !== 1
        ) {
            throw new AbsoluteHostingException(
                'Absolute Hosting returned an invalid domain name.',
                responseData: $response,
            );
        }

        return $domain;
    }

    private function expiryDate(array $response): string
    {
        $value = $this->requiredString(
            $response,
            'expiryDate',
            'Absolute Hosting domain information is missing expiryDate.',
        );

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            throw new AbsoluteHostingException(
                'Absolute Hosting returned an invalid domain expiry date.',
                responseData: $response,
            );
        }
    }

    private function nameservers(array $response): array
    {
        if (!array_key_exists('nameservers', $response)) {
            throw new AbsoluteHostingException(
                'Absolute Hosting domain information is missing nameservers.',
                responseData: $response,
            );
        }

        if (!is_array($response['nameservers'])) {
            throw new AbsoluteHostingException(
                'Absolute Hosting returned invalid domain nameservers.',
                responseData: $response,
            );
        }

        $nameservers = [];

        foreach ($response['nameservers'] as $nameserver) {
            if (!is_string($nameserver)) {
                throw new AbsoluteHostingException(
                    'Absolute Hosting returned an invalid domain nameserver.',
                    responseData: $response,
                );
            }

            $nameserver = strtolower(rtrim(trim($nameserver), '.'));

            if ($nameserver === '') {
                continue;
            }

            if (
                preg_match(
                    '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
                    $nameserver,
                ) !== 1
            ) {
                throw new AbsoluteHostingException(
                    'Absolute Hosting returned an invalid domain nameserver.',
                    responseData: $response,
                );
            }

            $nameservers[$nameserver] = $nameserver;
        }

        if ($nameservers === []) {
            throw new AbsoluteHostingException(
                'Absolute Hosting returned no usable domain nameservers.',
                responseData: $response,
            );
        }

        return array_values($nameservers);
    }

    private function requiredString(
        array $response,
        string $key,
        string $message,
    ): string {
        $value = $response[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new AbsoluteHostingException(
                $message,
                responseData: $response,
            );
        }

        return trim($value);
    }

    private function optionalBoolean(
        array $response,
        string $key,
    ): ?bool {
        if (
            !array_key_exists($key, $response)
            || $response[$key] === null
            || $response[$key] === ''
        ) {
            return null;
        }

        $value = filter_var(
            $response[$key],
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        );

        if ($value === null) {
            throw new AbsoluteHostingException(
                "Absolute Hosting returned invalid {$key}.",
                responseData: $response,
            );
        }

        return $value;
    }
}
