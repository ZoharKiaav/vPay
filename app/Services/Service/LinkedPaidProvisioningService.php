<?php

namespace App\Services\Service;

use App\Jobs\Server\CreateJob;
use App\Models\HostingDomainServiceLink;
use App\Models\Service;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Bus;
use Throwable;

class LinkedPaidProvisioningService
{
    private const DISPATCH_PROPERTY =
        'linked_provisioning_dispatched';

    public function handle(Collection $services): void
    {
        $services = $services
            ->filter(
                static fn (mixed $service): bool =>
                    $service instanceof Service,
            )
            ->keyBy('id');

        if ($services->isEmpty()) {
            return;
        }

        $handledServiceIds = [];

        $links = HostingDomainServiceLink::query()
            ->with([
                'domainService.product.server',
                'hostingService.product.server',
            ])
            ->whereIn(
                'hosting_service_id',
                $services->keys(),
            )
            ->whereIn(
                'domain_service_id',
                $services->keys(),
            )
            ->get();

        foreach ($links as $link) {
            $domainService = $link->domainService;
            $hostingService = $link->hostingService;

            if (
                $domainService === null
                || $hostingService === null
            ) {
                continue;
            }

            $handledServiceIds[] = $domainService->id;
            $handledServiceIds[] = $hostingService->id;

            $this->provisionLinkedPair(
                $domainService,
                $hostingService,
            );
        }

        $services
            ->except(array_values(array_unique(
                $handledServiceIds,
            )))
            ->each(function (Service $service): void {
                (new RenewServiceService)->handle($service);
            });
    }

    private function provisionLinkedPair(
        Service $domainService,
        Service $hostingService,
    ): void {
        if (
            $domainService->status !== Service::STATUS_PENDING
            || $hostingService->status !== Service::STATUS_PENDING
        ) {
            return;
        }

        if (
            $this->wasDispatched($domainService)
            || $this->wasDispatched($hostingService)
        ) {
            return;
        }

        $this->reserveDispatch($domainService);
        $this->reserveDispatch($hostingService);

        try {
            Bus::chain([
                new CreateJob(
                    service: $domainService,
                    activateOnSuccess: true,
                ),
                new CreateJob(
                    service: $hostingService,
                    activateOnSuccess: true,
                ),
            ])->dispatch();
        } catch (Throwable $exception) {
            $this->releaseDispatch($domainService);
            $this->releaseDispatch($hostingService);

            throw $exception;
        }
    }

    private function wasDispatched(
        Service $service,
    ): bool {
        return $service
            ->properties()
            ->where('key', self::DISPATCH_PROPERTY)
            ->exists();
    }

    private function reserveDispatch(
        Service $service,
    ): void {
        $service->properties()->updateOrCreate(
            [
                'key' => self::DISPATCH_PROPERTY,
            ],
            [
                'name' => 'Linked provisioning dispatched',
                'value' => now()->toIso8601String(),
            ],
        );
    }

    private function releaseDispatch(
        Service $service,
    ): void {
        $service
            ->properties()
            ->where('key', self::DISPATCH_PROPERTY)
            ->delete();
    }
}