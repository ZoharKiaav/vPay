<?php

namespace Tests\Feature;

use App\Models\HostingDomainServiceLink;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class HostingDomainServiceLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_links_services_in_the_same_order(): void
    {
        [$hostingService, $domainService] =
            $this->servicesInSameOrder();

        $link = HostingDomainServiceLink::query()->create([
            'hosting_service_id' => $hostingService->id,
            'domain_service_id' => $domainService->id,
        ]);

        $this->assertTrue(
            $link->hostingService->is($hostingService),
        );
        $this->assertTrue(
            $link->domainService->is($domainService),
        );

        $this->assertTrue(
            $hostingService
                ->fresh()
                ->linkedDomainService
                ->is($domainService),
        );

        $this->assertTrue(
            $domainService
                ->fresh()
                ->linkedHostingService
                ->is($hostingService),
        );

        $this->assertDatabaseHas(
            'hosting_domain_service_links',
            [
                'hosting_service_id' => $hostingService->id,
                'domain_service_id' => $domainService->id,
            ],
        );
    }

    public function test_it_rejects_self_linkage(): void
    {
        [$hostingService] = $this->servicesInSameOrder();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'A service cannot be linked to itself.',
        );

        HostingDomainServiceLink::query()->create([
            'hosting_service_id' => $hostingService->id,
            'domain_service_id' => $hostingService->id,
        ]);
    }

    public function test_it_rejects_cross_user_linkage(): void
    {
        [$hostingService] = $this->servicesInSameOrder();
        [, $foreignDomainService] =
            $this->servicesInSameOrder();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Linked services must belong to the same user.',
        );

        HostingDomainServiceLink::query()->create([
            'hosting_service_id' => $hostingService->id,
            'domain_service_id' => $foreignDomainService->id,
        ]);
    }

    public function test_it_rejects_cross_order_linkage(): void
    {
        $user = User::factory()->create();

        [$hostingService] =
            $this->servicesInSameOrder($user);

        [, $domainService] =
            $this->servicesInSameOrder($user);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Linked services must belong to the same order.',
        );

        HostingDomainServiceLink::query()->create([
            'hosting_service_id' => $hostingService->id,
            'domain_service_id' => $domainService->id,
        ]);
    }

    public function test_hosting_service_link_is_unique(): void
    {
        [$hostingService, $domainService] =
            $this->servicesInSameOrder();

        $secondDomainService = $this->service(
            $hostingService->user,
            $hostingService->order,
        );

        HostingDomainServiceLink::query()->create([
            'hosting_service_id' => $hostingService->id,
            'domain_service_id' => $domainService->id,
        ]);

        $this->expectException(QueryException::class);

        HostingDomainServiceLink::query()->create([
            'hosting_service_id' => $hostingService->id,
            'domain_service_id' => $secondDomainService->id,
        ]);
    }

    public function test_domain_service_link_is_unique(): void
    {
        [$hostingService, $domainService] =
            $this->servicesInSameOrder();

        $secondHostingService = $this->service(
            $hostingService->user,
            $hostingService->order,
        );

        HostingDomainServiceLink::query()->create([
            'hosting_service_id' => $hostingService->id,
            'domain_service_id' => $domainService->id,
        ]);

        $this->expectException(QueryException::class);

        HostingDomainServiceLink::query()->create([
            'hosting_service_id' => $secondHostingService->id,
            'domain_service_id' => $domainService->id,
        ]);
    }

    public function test_deleting_a_service_removes_the_link(): void
    {
        [$hostingService, $domainService] =
            $this->servicesInSameOrder();

        $link = HostingDomainServiceLink::query()->create([
            'hosting_service_id' => $hostingService->id,
            'domain_service_id' => $domainService->id,
        ]);

        $hostingService->delete();

        $this->assertDatabaseMissing(
            'hosting_domain_service_links',
            ['id' => $link->id],
        );
    }

    private function servicesInSameOrder(
        ?User $user = null,
    ): array {
        $user ??= User::factory()->create();

        $order = Order::query()->create([
            'user_id' => $user->id,
            'currency_code' => 'ZAR',
        ]);

        return [
            $this->service($user, $order),
            $this->service($user, $order),
        ];
    }

    private function service(
        User $user,
        Order $order,
    ): Service {
        return Service::factory()->create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'status' => Service::STATUS_PENDING,
            'currency_code' => 'ZAR',
        ]);
    }
}
