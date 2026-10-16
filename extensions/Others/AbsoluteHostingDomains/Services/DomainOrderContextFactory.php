<?php

namespace Paymenter\Extensions\Others\AbsoluteHostingDomains\Services;

use App\Models\Service;
use App\Models\User;
use LogicException;

class DomainOrderContextFactory
{
    private const REQUIRED_SERVICE_PROPERTIES = [
        'domain_action',
        'domain_sld',
        'domain_tld',
        'registration_period',
        'nameserver_1',
        'nameserver_2',
    ];

    private const OPTIONAL_SERVICE_PROPERTIES = [
        'nameserver_3',
        'nameserver_4',
        'nameserver_5',
        'epp_code',
    ];

    public function __construct(
        private readonly CustomerPropertyExtractor $propertyExtractor,
    ) {}

    public function make(
        Service $service,
        array $serviceProperties,
    ): array {
        $customer = $this->customer($service);

        return [
            'action' => $this->action($serviceProperties),
            'service_properties' => $this->serviceProperties(
                $serviceProperties,
            ),
            'customer' => [
                'first_name' => $this->requiredString(
                    $customer->first_name,
                    'The customer first name is required.',
                ),
                'last_name' => $this->requiredString(
                    $customer->last_name,
                    'The customer last name is required.',
                ),
                'email' => $this->requiredString(
                    $customer->email,
                    'The customer email address is required.',
                ),
            ],
            'customer_properties' => $this->propertyExtractor->extract(
                $customer->properties,
            ),
        ];
    }

    private function customer(Service $service): User
    {
        if (!$service->relationLoaded('user')) {
            throw new LogicException(
                'The service customer relationship must be loaded before preparing a domain order.',
            );
        }

        $customer = $service->getRelation('user');

        if (!$customer instanceof User) {
            throw new LogicException(
                'The service must belong to a customer before preparing a domain order.',
            );
        }

        if (!$customer->relationLoaded('properties')) {
            throw new LogicException(
                'The customer properties relationship must be loaded before preparing a domain order.',
            );
        }

        return $customer;
    }

    private function action(array $properties): string
    {
        $action = strtolower($this->requiredString(
            $properties['domain_action'] ?? null,
            'The domain action is required.',
        ));

        if (!in_array($action, ['register', 'transfer'], true)) {
            throw new LogicException(
                'The domain action must be register or transfer.',
            );
        }

        return $action;
    }

    private function serviceProperties(array $properties): array
    {
        $prepared = [];

        foreach (self::REQUIRED_SERVICE_PROPERTIES as $key) {
            $prepared[$key] = $this->requiredString(
                $properties[$key] ?? null,
                "The service property {$key} is required.",
            );
        }

        foreach (self::OPTIONAL_SERVICE_PROPERTIES as $key) {
            $prepared[$key] = trim(
                (string) ($properties[$key] ?? ''),
            );
        }

        if (
            $prepared['domain_action'] === 'transfer'
            && $prepared['epp_code'] === ''
        ) {
            throw new LogicException(
                'The service property epp_code is required for a domain transfer.',
            );
        }

        return $prepared;
    }

    private function requiredString(
        mixed $value,
        string $message,
    ): string {
        if (!is_scalar($value)) {
            throw new LogicException($message);
        }

        $value = trim((string) $value);

        if ($value === '') {
            throw new LogicException($message);
        }

        return $value;
    }
}
