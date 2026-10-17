<?php

namespace Paymenter\Extensions\Others\AbsoluteHostingDomains\Services;

use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingClient;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingException;

class DomainCatalogService
{
    public function __construct(
        private readonly AbsoluteHostingClient $client,
    ) {}

    public function tlds(): array
    {
        $response = $this->client->tlds();

        if (!is_array($response)) {
            throw new AbsoluteHostingException(
                'Absolute Hosting returned an invalid TLD catalogue.',
                responseData: $response,
            );
        }

        $tlds = [];

        foreach ($response as $tld) {
            if (!is_string($tld) || trim($tld) === '') {
                throw new AbsoluteHostingException(
                    'Absolute Hosting returned an invalid TLD value.',
                    responseData: $response,
                );
            }

            $normalised = $this->normaliseTld($tld);
            $tlds[$normalised] = $normalised;
        }

        ksort($tlds);

        return array_values($tlds);
    }

    public function pricing(): array
    {
        $response = $this->client->tldPricing();

        if (!is_array($response)) {
            throw new AbsoluteHostingException(
                'Absolute Hosting returned invalid TLD pricing.',
                responseData: $response,
            );
        }

        $pricing = [];

        foreach ($response as $record) {
            if (!is_array($record)) {
                throw new AbsoluteHostingException(
                    'Absolute Hosting returned an invalid pricing record.',
                    responseData: $record,
                );
            }

            $normalised = $this->normalisePricingRecord($record);
            $pricing[$normalised['tld']] = $normalised;
        }

        ksort($pricing);

        return $pricing;
    }

    public function pricingFor(string $tld): ?array
    {
        $normalisedTld = $this->normaliseTld($tld);

        return $this->pricing()[$normalisedTld] ?? null;
    }

    public function availability(
        string $searchTerm,
        array $tldsToInclude,
    ): array {
        $normalisedSearchTerm = strtolower(
            rtrim(trim($searchTerm), '.')
        );

        if ($normalisedSearchTerm === '') {
            throw new AbsoluteHostingException(
                'A domain search term is required.',
            );
        }

        $normalisedTlds = array_values(array_unique(array_map(
            fn (string $tld): string => $this->normaliseTld($tld),
            $tldsToInclude,
        )));

        if ($normalisedTlds === []) {
            throw new AbsoluteHostingException(
                'At least one TLD is required for availability lookup.',
            );
        }

        $response = $this->client->lookup(
            searchTerm: $normalisedSearchTerm,
            tldsToInclude: $normalisedTlds,
            premiumEnabled: false,
            isIdnDomain: false,
        );

        if (!is_array($response)) {
            throw new AbsoluteHostingException(
                'Absolute Hosting returned invalid availability results.',
                responseData: $response,
            );
        }

        return array_map(
            fn (mixed $record): array => $this->normaliseLookupRecord(
                $record,
            ),
            $response,
        );
    }

    private function normalisePricingRecord(array $record): array
    {
        $required = [
            'tld',
            'registrationPrice',
            'renewalPrice',
            'transferPrice',
            'graceFee',
            'graceDays',
            'redemptionDays',
            'redemptionFee',
            'currencyCode',
            'years',
        ];

        foreach ($required as $field) {
            if (!array_key_exists($field, $record)) {
                throw new AbsoluteHostingException(
                    "Absolute Hosting pricing is missing {$field}.",
                    responseData: $record,
                );
            }
        }

        if (!is_array($record['years'])) {
            throw new AbsoluteHostingException(
                'Absolute Hosting returned invalid pricing years.',
                responseData: $record,
            );
        }

        $years = array_values(array_map(
            static fn (mixed $year): int => (int) $year,
            $record['years'],
        ));

        return [
            'tld' => $this->normaliseTld((string) $record['tld']),
            'registration_price' => $this->normaliseMoney(
                $record['registrationPrice'],
            ),
            'renewal_price' => $this->normaliseMoney(
                $record['renewalPrice'],
            ),
            'transfer_price' => $this->normaliseMoney(
                $record['transferPrice'],
            ),
            'grace_fee' => $this->normaliseMoney(
                $record['graceFee'],
            ),
            'grace_days' => $this->normaliseInteger(
                $record['graceDays'],
            ),
            'redemption_days' => $this->normaliseInteger(
                $record['redemptionDays'],
            ),
            'redemption_fee' => $this->normaliseMoney(
                $record['redemptionFee'],
            ),
            'currency_code' => strtoupper(
                (string) $record['currencyCode'],
            ),
            'years' => $years,
            'registration_available' => $this->normaliseMoney(
                $record['registrationPrice'],
            ) !== null,
        ];
    }

    private function normaliseLookupRecord(mixed $record): array
    {
        if (!is_array($record)) {
            throw new AbsoluteHostingException(
                'Absolute Hosting returned an invalid lookup record.',
                responseData: $record,
            );
        }

        $required = [
            'domainName',
            'tld',
            'sld',
            'status',
            'legacyStatus',
            'isRegistered',
            'isAvailable',
            'isValidDomain',
            'domainErrorMessage',
            'isPremium',
        ];

        foreach ($required as $field) {
            if (!array_key_exists($field, $record)) {
                throw new AbsoluteHostingException(
                    "Absolute Hosting lookup is missing {$field}.",
                    responseData: $record,
                );
            }
        }

        $errorMessage = trim(
            (string) $record['domainErrorMessage']
        );

        return [
            'domain_name' => strtolower(
                rtrim(trim((string) $record['domainName']), '.'),
            ),
            'idn_domain_name' => strtolower(rtrim(trim(
                (string) ($record['idnDomainName']
                    ?? $record['domainName']),
            ), '.')),
            'tld' => $this->normaliseTld(
                (string) $record['tld'],
            ),
            'sld' => strtolower(
                trim((string) $record['sld']),
            ),
            'status' => (string) $record['status'],
            'legacy_status' => (string) $record['legacyStatus'],
            'is_registered' => (bool) $record['isRegistered'],
            'is_available' => (bool) $record['isAvailable'],
            'is_valid' => (bool) $record['isValidDomain'],
            'error_message' => $errorMessage === ''
                ? null
                : $errorMessage,
            'is_premium' => (bool) $record['isPremium'],
        ];
    }

    private function normaliseTld(string $tld): string
    {
        $normalised = strtolower(ltrim(trim($tld), '.'));

        if ($normalised === '') {
            throw new AbsoluteHostingException(
                'A valid TLD is required.',
            );
        }

        return '.' . $normalised;
    }

    private function normaliseMoney(mixed $value): ?string
    {
        if (!is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function normaliseInteger(mixed $value): ?int
    {
        if (!is_numeric($value) || (int) $value < 0) {
            return null;
        }

        return (int) $value;
    }
}
