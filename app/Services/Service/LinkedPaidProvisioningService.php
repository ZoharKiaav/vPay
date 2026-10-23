<?php

namespace App\Services\Service;

use App\Jobs\Server\CreateJob;
use App\Models\HostingDomainServiceLink;
use App\Models\Service;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Bus;

class LinkedPaidProvisioningService
{
    public function handle(Collection $services): void
    {
        $services = $services
            ->filter(
                static fn (mixed $service): bool => $service instanceof Service,
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

        Bus::chain([
            new CreateJob($domainService),
            new CreateJob($hostingService),
        ])->dispatch();

        $this->activate($domainService);
        $this->activate($hostingService);
    }

    private function activate(Service $service): void
    {
        $service->expires_at =
            $service->calculateNextDueDate();

        $service->status = Service::STATUS_ACTIVE;
        $service->save();
    }
}
