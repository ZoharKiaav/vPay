<?php

namespace Tests\Feature\Services;

use App\Jobs\Server\CreateJob;
use App\Models\HostingDomainServiceLink;
use App\Models\Order;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\LinkedPaidProvisioningService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LinkedPaidProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_it_chains_domain_before_linked_hosting(): void
    {
        [$domainService, $hostingService] =
            $this->linkedServices();

        $this->service()->handle(new Collection([
            $hostingService,
            $domainService,
        ]));

        Queue::assertPushedWithChain(
            CreateJob::class,
            [CreateJob::class],
            fn (CreateJob $job): bool => $job->service->is($domainService),
        );

        Queue::assertPushed(
            CreateJob::class,
            fn (CreateJob $job): bool => $job->service->is($domainService),
        );

        Queue::assertNotPushed(
            CreateJob::class,
            fn (CreateJob $job): bool => $job->service->is($hostingService),
        );

        $this->assertSame(
            Service::STATUS_ACTIVE,
            $domainService->fresh()->status,
        );

        $this->assertSame(
            Service::STATUS_ACTIVE,
            $hostingService->fresh()->status,
        );
    }

    public function test_reprocessing_does_not_dispatch_the_pair_again(): void
    {
        [$domainService, $hostingService] =
            $this->linkedServices();

        $services = new Collection([
            $domainService,
            $hostingService,
        ]);

        $this->service()->handle($services);
        $this->service()->handle(new Collection([
            $domainService->fresh(),
            $hostingService->fresh(),
        ]));

        Queue::assertPushed(CreateJob::class, 1);
    }

    public function test_unlinked_hosting_uses_the_existing_lifecycle(): void
    {
        $hostingService = $this->serviceRecord(
            $this->server('Hestia'),
        );

        $this->service()->handle(new Collection([
            $hostingService,
        ]));

        Queue::assertPushed(
            CreateJob::class,
            fn (CreateJob $job): bool => $job->service->is($hostingService),
        );

        Queue::assertPushed(CreateJob::class, 1);

        $this->assertSame(
            Service::STATUS_ACTIVE,
            $hostingService->fresh()->status,
        );
    }

    public function test_a_link_is_only_used_when_both_services_are_present(): void
    {
        [$domainService, $hostingService] =
            $this->linkedServices();

        $this->service()->handle(new Collection([
            $domainService,
        ]));

        Queue::assertPushed(
            CreateJob::class,
            fn (CreateJob $job): bool => $job->service->is($domainService),
        );

        Queue::assertPushed(CreateJob::class, 1);

        $this->assertSame(
            Service::STATUS_PENDING,
            $hostingService->fresh()->status,
        );
    }

    private function linkedServices(): array
    {
        $user = User::factory()->create();

        $order = new Order([
            'user_id' => $user->id,
            'currency_code' => 'USD',
        ]);

        $order->save();

        $domainService = $this->serviceRecord(
            $this->server('AbsoluteHostingDomains'),
            $user,
            $order->id,
        );

        $hostingService = $this->serviceRecord(
            $this->server('Hestia'),
            $user,
            $order->id,
        );

        HostingDomainServiceLink::query()->create([
            'hosting_service_id' => $hostingService->id,
            'domain_service_id' => $domainService->id,
        ]);

        return [$domainService, $hostingService];
    }

    private function serviceRecord(
        Server $server,
        ?User $user = null,
        ?int $orderId = null,
    ): Service {
        $user ??= User::factory()->create();

        $fixture = $this->createProduct([
            'server_id' => $server->id,
        ]);

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_PENDING,
        ]);

        if ($orderId !== null) {
            $service->order_id = $orderId;
            $service->save();
        }

        return $service;
    }

    private function server(string $extension): Server
    {
        return Server::query()->create([
            'name' => $extension . ' fixture',
            'extension' => $extension,
            'type' => 'server',
            'enabled' => true,
        ]);
    }

    private function service(): LinkedPaidProvisioningService
    {
        return new LinkedPaidProvisioningService;
    }
}
