<?php

namespace Paymenter\Extensions\Others\AbsoluteHostingDomains\Services;

use LogicException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingClient;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Models\ManagedDomain;
use Throwable;

class DomainOrderService
{
    public const PROVIDER_STATUS_SUBMITTING = 'submitting';

    public const PROVIDER_STATUS_SUBMITTED = 'submitted';

    public const PROVIDER_STATUS_UNCERTAIN = 'uncertain';

    public function __construct(
        private readonly AbsoluteHostingClient $client,
        private readonly DomainOrderPayloadFactory $payloadFactory,
    ) {}

    public function submit(
        ManagedDomain $managedDomain,
        string $action,
        array $serviceProperties,
        array $customer,
        array $customerProperties,
    ): array {
        $this->guardAgainstDuplicateOrder($managedDomain);

        $payload = $this->payloadFactory->make(
            $action,
            $serviceProperties,
            $customer,
            $customerProperties,
        );

        $this->prepareSubmission(
            $managedDomain,
            $action,
            $payload,
        );

        try {
            $response = $this->submitToProvider(
                $action,
                $payload,
            );
        } catch (Throwable $exception) {
            $this->markUncertain(
                $managedDomain,
                $action,
                $exception,
            );

            throw $exception;
        }

        $this->markSubmitted(
            $managedDomain,
            $action,
            $response,
        );

        return $response;
    }

    private function guardAgainstDuplicateOrder(
        ManagedDomain $managedDomain,
    ): void {
        if (
            $managedDomain->provider_status !== null
            && trim((string) $managedDomain->provider_status) !== ''
        ) {
            throw new LogicException(
                'A provider order has already been attempted for this service.',
            );
        }
    }

    private function prepareSubmission(
        ManagedDomain $managedDomain,
        string $action,
        array $payload,
    ): void {
        $managedDomain->domain_name = $payload['domain'];
        $managedDomain->tld = $this->extractTld(
            $payload['domain'],
        );
        $managedDomain->registration_period = $payload['regperiod'];
        $managedDomain->status = $action === 'transfer'
            ? ManagedDomain::STATUS_TRANSFER_PENDING
            : ManagedDomain::STATUS_PENDING;
        $managedDomain->provider_status = self::PROVIDER_STATUS_SUBMITTING;
        $managedDomain->provider_reference = null;
        $managedDomain->submission_started_at = now();
        $managedDomain->submission_confirmed_at = null;
        $managedDomain->nameservers = $this->normaliseNameservers(
            $payload['nameservers'],
        );
        $managedDomain->contact_data = $payload['contacts'];
        $managedDomain->provider_data = [
            'operation' => $action,
            'submission_state' => self::PROVIDER_STATUS_SUBMITTING,
            'submission_started_at' =>
                $managedDomain->submission_started_at
                    ?->toIso8601String(),
        ];

        $managedDomain->save();
    }

    private function submitToProvider(
        string $action,
        array $payload,
    ): array {
        $response = $action === 'transfer'
            ? $this->client->transferDomain($payload)
            : $this->client->registerDomain($payload);

        if (!is_array($response)) {
            throw new LogicException(
                'Absolute Hosting returned an invalid domain order response.',
            );
        }

        return $response;
    }

    private function markSubmitted(
        ManagedDomain $managedDomain,
        string $action,
        array $response,
    ): void {
        $managedDomain->provider_status =
            self::PROVIDER_STATUS_SUBMITTED;

        $managedDomain->provider_reference =
            $this->providerReference($response);

        $managedDomain->submission_confirmed_at = now();

        $providerData = is_array($managedDomain->provider_data)
            ? $managedDomain->provider_data
            : [];

        $providerData['operation'] = $action;
        $providerData['submission_state'] =
            self::PROVIDER_STATUS_SUBMITTED;
        $providerData['submission_confirmed_at'] =
            $managedDomain->submission_confirmed_at
                ?->toIso8601String();
        $providerData['response'] = $response;

        $managedDomain->provider_data = $providerData;
        $managedDomain->save();
    }

    private function markUncertain(
        ManagedDomain $managedDomain,
        string $action,
        Throwable $exception,
    ): void {
        $managedDomain->provider_status =
            self::PROVIDER_STATUS_UNCERTAIN;

        $providerData = is_array($managedDomain->provider_data)
            ? $managedDomain->provider_data
            : [];

        $providerData['operation'] = $action;
        $providerData['submission_state'] =
            self::PROVIDER_STATUS_UNCERTAIN;
        $providerData['exception'] = $exception::class;
        $providerData['message'] = $exception->getMessage();

        $managedDomain->provider_data = $providerData;
        $managedDomain->save();
    }

    private function providerReference(
        array $response,
    ): ?string {
        foreach ([
            'order_reference',
            'orderReference',
            'order_id',
            'orderId',
            'id',
        ] as $key) {
            $value = $response[$key] ?? null;

            if (
                is_scalar($value)
                && trim((string) $value) !== ''
            ) {
                return trim((string) $value);
            }
        }

        return null;
    }
    private function extractTld(string $domain): string
    {
        $labels = explode('.', strtolower($domain));

        array_shift($labels);

        if ($labels === []) {
            throw new LogicException(
                'The domain order contains no domain extension.',
            );
        }

        return '.' . implode('.', $labels);
    }

    private function normaliseNameservers(array $nameservers): array
    {
        if (array_is_list($nameservers)) {
            return array_values(
                array_filter(
                    $nameservers,
                    static fn (string $value): bool => $value !== '',
                ),
            );
        }

        return array_values(
            array_filter(
                $nameservers,
                static fn (string $value): bool => $value !== '',
            ),
        );
    }
}
