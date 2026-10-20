<?php

namespace Paymenter\Extensions\Others\AbsoluteHostingDomains\Services;

use InvalidArgumentException;

class DomainOrderPayloadFactory
{
    private const ACTION_REGISTER = 'register';

    private const ACTION_TRANSFER = 'transfer';

    private const REQUIRED_CONTACT_PROPERTIES = [
        'address',
        'city',
        'state',
        'zip',
        'country',
        'phone',
    ];

    public function make(
        string $action,
        array $serviceProperties,
        array $customer,
        array $customerProperties,
    ): array {
        $action = $this->normaliseAction($action);
        $domain = $this->domainName($serviceProperties);
        $period = $this->registrationPeriod($serviceProperties);
        $contact = $this->contact($customer, $customerProperties);
        $nameservers = $this->nameservers($serviceProperties);

        $payload = [
            'domain' => $domain,
            'regperiod' => $period,
            'idnLanguage' => '',
            'domainfields' => base64_encode(serialize(
                $this->domainFields($serviceProperties),
            )),
            'addons' => [
                'dnsmanagement' => 0,
                'emailforwarding' => 0,
                'idprotection' => 0,
            ],
            'nameservers' => $action === self::ACTION_REGISTER
                ? $this->registrationNameservers($nameservers)
                : $nameservers,
            'contacts' => [
                'registrant' => $contact,
            ],
        ];

        if ($action === self::ACTION_TRANSFER) {
            $payload['eppcode'] = $this->requiredString(
                $serviceProperties,
                'epp_code',
                'An EPP code is required for a domain transfer.',
            );
        }

        return $payload;
    }

    private function normaliseAction(string $action): string
    {
        $action = strtolower(trim($action));

        if (!in_array(
            $action,
            [self::ACTION_REGISTER, self::ACTION_TRANSFER],
            true,
        )) {
            throw new InvalidArgumentException(
                'The domain action must be register or transfer.',
            );
        }

        return $action;
    }

    private function domainName(array $properties): string
    {
        $sld = strtolower($this->requiredString(
            $properties,
            'domain_sld',
            'The domain label is required.',
        ));

        $tld = strtolower($this->requiredString(
            $properties,
            'domain_tld',
            'The domain extension is required.',
        ));

        return $sld . '.' . ltrim($tld, '.');
    }

    private function registrationPeriod(array $properties): int
    {
        $period = filter_var(
            $properties['registration_period'] ?? null,
            FILTER_VALIDATE_INT,
        );

        if ($period === false || $period < 1) {
            throw new InvalidArgumentException(
                'The registration period must be a positive integer.',
            );
        }

        return $period;
    }

    private function contact(
        array $customer,
        array $properties,
    ): array {
        foreach (self::REQUIRED_CONTACT_PROPERTIES as $property) {
            $this->requiredString(
                $properties,
                $property,
                "The customer property {$property} is required.",
            );
        }

        return [
            'firstname' => $this->requiredString(
                $customer,
                'first_name',
                'The customer first name is required.',
            ),
            'lastname' => $this->requiredString(
                $customer,
                'last_name',
                'The customer last name is required.',
            ),
            'companyname' => $this->optionalString(
                $properties,
                'company_name',
            ),
            'email' => $this->requiredString(
                $customer,
                'email',
                'The customer email address is required.',
            ),
            'address1' => $this->requiredString(
                $properties,
                'address',
                'The customer property address is required.',
            ),
            'address2' => $this->optionalString(
                $properties,
                'address2',
            ),
            'city' => $this->requiredString(
                $properties,
                'city',
                'The customer property city is required.',
            ),
            'state' => $this->requiredString(
                $properties,
                'state',
                'The customer property state is required.',
            ),
            'postcode' => $this->requiredString(
                $properties,
                'zip',
                'The customer property zip is required.',
            ),
            'country' => $this->normaliseCountry(
                $this->requiredString(
                    $properties,
                    'country',
                    'The customer property country is required.',
                ),
            ),
            'phonenumber' => $this->normalisePhone(
                $this->requiredString(
                    $properties,
                    'phone',
                    'The customer property phone is required.',
                ),
            ),
            'tax_id' => $this->optionalString(
                $properties,
                'tax_id',
            ),
        ];
    }

    private function nameservers(array $properties): array
    {
        $nameservers = [
            $this->requiredString(
                $properties,
                'nameserver_1',
                'The primary nameserver is required.',
            ),
            $this->requiredString(
                $properties,
                'nameserver_2',
                'The secondary nameserver is required.',
            ),
            $this->optionalString($properties, 'nameserver_3'),
            $this->optionalString($properties, 'nameserver_4'),
            $this->optionalString($properties, 'nameserver_5'),
        ];

        return array_map(
            static fn (string $nameserver): string => strtolower(
                rtrim($nameserver, '.'),
            ),
            $nameservers,
        );
    }

    private function registrationNameservers(
        array $nameservers,
    ): array {
        return [
            'ns1' => $nameservers[0],
            'ns2' => $nameservers[1],
            'ns3' => $nameservers[2],
            'ns4' => $nameservers[3],
            'ns5' => $nameservers[4],
        ];
    }

    private function domainFields(array $properties): array
    {
        $fields = $properties['domain_fields'] ?? [];

        if (!is_array($fields)) {
            throw new InvalidArgumentException(
                'Domain-specific fields must be an array.',
            );
        }

        $normalised = [];

        foreach ($fields as $key => $value) {
            $key = trim((string) $key);

            if ($key === '') {
                throw new InvalidArgumentException(
                    'Domain-specific field names cannot be empty.',
                );
            }

            if (
                !is_scalar($value)
                && $value !== null
            ) {
                throw new InvalidArgumentException(
                    'Domain-specific field values must be scalar.',
                );
            }

            $normalised[$key] = is_string($value)
                ? trim($value)
                : $value;
        }

        return $normalised;
    }

    private function normalisePhone(string $phone): string
    {
        $phone = trim($phone);

        if (
            strlen($phone) > 32
            || preg_match(
                '/^\+?[0-9][0-9\s().-]{5,31}$/',
                $phone,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'The customer phone number is invalid.',
            );
        }

        return $phone;
    }

    private function normaliseCountry(string $country): string
    {
        $country = trim($country);

        if (strlen($country) === 2) {
            return strtoupper($country);
        }

        foreach (config('app.countries', []) as $code => $name) {
            if (strcasecmp((string) $name, $country) === 0) {
                return strtoupper((string) $code);
            }
        }

        throw new InvalidArgumentException(
            'The customer country must be a recognised two-letter code or country name.',
        );
    }

    private function requiredString(
        array $values,
        string $key,
        string $message,
    ): string {
        $value = trim((string) ($values[$key] ?? ''));

        if ($value === '') {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private function optionalString(
        array $values,
        string $key,
    ): string {
        return trim((string) ($values[$key] ?? ''));
    }
}
