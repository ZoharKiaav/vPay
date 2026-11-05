<?php

namespace App\Services\Service;

use App\Models\HostingDomainServiceLink;
use App\Models\Service;
use Throwable;

class LinkedProvisioningRecoveryService
{
    public const DISPATCH_PROPERTY =
        'linked_provisioning_dispatched';

    public const FAILURE_PROPERTY =
        'linked_provisioning_failed';

    public const FAILURE_CLASS_PROPERTY =
        'linked_provisioning_failure_class';

    public const FAILURE_TIME_PROPERTY =
        'linked_provisioning_failed_at';

    public function recordFailure(
        Service $failedService,
        Throwable $exception,
    ): void {
        $failedService = $failedService->fresh();

        if ($failedService === null) {
            return;
        }

        $this->failureProperty(
            $failedService,
            self::FAILURE_PROPERTY,
            $exception->getMessage(),
            'Linked provisioning failure',
        );

        $this->failureProperty(
            $failedService,
            self::FAILURE_CLASS_PROPERTY,
            $exception::class,
            'Linked provisioning failure class',
        );

        $this->failureProperty(
            $failedService,
            self::FAILURE_TIME_PROPERTY,
            now()->toIso8601String(),
            'Linked provisioning failure time',
        );

        $this->releasePairReservations($failedService);
    }

    public function clearFailure(Service $service): void
    {
        $service->properties()
            ->whereIn('key', [
                self::FAILURE_PROPERTY,
                self::FAILURE_CLASS_PROPERTY,
                self::FAILURE_TIME_PROPERTY,
            ])
            ->delete();
    }

    public function completeIfPairActive(
        Service $service,
    ): void {
        $link = $this->linkFor($service);

        if ($link === null) {
            return;
        }

        $link->loadMissing([
            'domainService',
            'hostingService',
        ]);

        $domainService = $link->domainService;
        $hostingService = $link->hostingService;

        if (
            $domainService === null
            || $hostingService === null
            || $domainService->status
                !== Service::STATUS_ACTIVE
            || $hostingService->status
                !== Service::STATUS_ACTIVE
        ) {
            return;
        }

        $this->releaseReservation($domainService);
        $this->releaseReservation($hostingService);

        $this->clearFailure($domainService);
        $this->clearFailure($hostingService);
    }

    public function releasePairReservations(
        Service $service,
    ): void {
        $link = $this->linkFor($service);

        if ($link === null) {
            $this->releaseReservation($service);

            return;
        }

        $link->loadMissing([
            'domainService',
            'hostingService',
        ]);

        if ($link->domainService !== null) {
            $this->releaseReservation(
                $link->domainService,
            );
        }

        if ($link->hostingService !== null) {
            $this->releaseReservation(
                $link->hostingService,
            );
        }
    }

    private function linkFor(
        Service $service,
    ): ?HostingDomainServiceLink {
        return HostingDomainServiceLink::query()
            ->where(function ($query) use ($service): void {
                $query
                    ->where(
                        'hosting_service_id',
                        $service->id,
                    )
                    ->orWhere(
                        'domain_service_id',
                        $service->id,
                    );
            })
            ->first();
    }

    private function releaseReservation(
        Service $service,
    ): void {
        $service->properties()
            ->where(
                'key',
                self::DISPATCH_PROPERTY,
            )
            ->delete();
    }

    private function failureProperty(
        Service $service,
        string $key,
        string $value,
        string $name,
    ): void {
        $service->properties()->updateOrCreate(
            [
                'key' => $key,
            ],
            [
                'name' => $name,
                'value' => $value,
            ],
        );
    }
}