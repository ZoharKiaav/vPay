<?php

namespace Tests\Feature\Services;

use App\Jobs\Server\CreateJob;
use App\Models\HostingDomainServiceLink;
use App\Models\Order;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\LinkedPaidProvisioningService;
use App\Services\Service\LinkedProvisioningRecoveryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class LinkedProvisioningRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_failed_domain_job_records_failure_and_releases_pair_reservations(): void
    {
        [$domainService, $hostingService] =
            $this->linkedServices();

        $this->reserve($domainService);
        $this->reserve($hostingService);

        $job = new CreateJob(
            service: $domainService,
            sendNotification: false,
            activateOnSuccess: true,
        );

        $job->failed(new RuntimeException(
            'Fixture domain provider failure',
        ));

        $this->assertSame(
            Service::STATUS_PENDING,
            $domainService->fresh()->status,
        );

        $this->assertSame(
            Service::STATUS_PENDING,
            $hostingService->fresh()->status,
        );

        $this->assertDatabaseHas(
            'properties',
            [
                'model_type' => Service::class,
                'model_id' => $domainService->id,
                'key' => LinkedProvisioningRecoveryService::FAILURE_PROPERTY,
                'value' => 'Fixture domain provider failure',
            ],
        );

        $this->assertDatabaseHas(
            'properties',
            [
                'model_type' => Service::class,
                'model_id' => $domainService->id,
                'key' => LinkedProvisioningRecoveryService::FAILURE_CLASS_PROPERTY,
                'value' => RuntimeException::class,
            ],
        );

        $this->assertDatabaseHas(
            'properties',
            [
                'model_type' => Service::class,
                'model_id' => $domainService->id,
                'key' => LinkedProvisioningRecoveryService::FAILURE_TIME_PROPERTY,
            ],
        );

        $this->assertReservationMissing($domainService);
        $this->assertReservationMissing($hostingService);

        Queue::assertNothingPushed();
    }

    public function test_active_domain_and_pending_hosting_resume_hosting_only(): void
    {
        [$domainService, $hostingService] =
            $this->linkedServices();

        $domainService->forceFill([
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addYear(),
        ])->save();

        $this->service()->handle(new Collection([
            $domainService->fresh(),
            $hostingService->fresh(),
        ]));

        Queue::assertPushed(
            CreateJob::class,
            function (CreateJob $job) use (
                $hostingService,
            ): bool {
                return $job->service->is($hostingService)
                    && $job->activateOnSuccess;
            },
        );

        Queue::assertNotPushed(
            CreateJob::class,
            function (CreateJob $job) use (
                $domainService,
            ): bool {
                return $job->service->is($domainService);
            },
        );

        Queue::assertPushed(CreateJob::class, 1);

        $this->assertReservationExists($domainService);
        $this->assertReservationExists($hostingService);

        $this->assertSame(
            Service::STATUS_ACTIVE,
            $domainService->fresh()->status,
        );

        $this->assertSame(
            Service::STATUS_PENDING,
            $hostingService->fresh()->status,
        );
    }

    public function test_active_pair_clears_reservations_and_stale_failure_metadata(): void
    {
        [$domainService, $hostingService] =
            $this->linkedServices();

        foreach (
            [$domainService, $hostingService]
            as $service
        ) {
            $service->forceFill([
                'status' => Service::STATUS_ACTIVE,
                'expires_at' => now()->addYear(),
            ])->save();

            $this->reserve($service);

            $service->properties()->createMany([
                [
                    'key' => LinkedProvisioningRecoveryService::FAILURE_PROPERTY,
                    'value' => 'Fixture failure',
                ],
                [
                    'key' => LinkedProvisioningRecoveryService::FAILURE_CLASS_PROPERTY,
                    'value' => RuntimeException::class,
                ],
                [
                    'key' => LinkedProvisioningRecoveryService::FAILURE_TIME_PROPERTY,
                    'value' => now()->toIso8601String(),
                ],
            ]);
        }

        app(LinkedProvisioningRecoveryService::class)
            ->completeIfPairActive(
                $hostingService->fresh(),
            );

        foreach (
            [$domainService, $hostingService]
            as $service
        ) {
            $this->assertReservationMissing($service);

            foreach ([
                LinkedProvisioningRecoveryService::FAILURE_PROPERTY,
                LinkedProvisioningRecoveryService::FAILURE_CLASS_PROPERTY,
                LinkedProvisioningRecoveryService::FAILURE_TIME_PROPERTY,
            ] as $key) {
                $this->assertDatabaseMissing(
                    'properties',
                    [
                        'model_type' => Service::class,
                        'model_id' => $service->id,
                        'key' => $key,
                    ],
                );
            }
        }

        Queue::assertNothingPushed();
    }

    public function test_pending_domain_and_active_hosting_do_not_dispatch(): void
    {
        [$domainService, $hostingService] =
            $this->linkedServices();

        $hostingService->forceFill([
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addMonth(),
        ])->save();

        $this->service()->handle(new Collection([
            $domainService->fresh(),
            $hostingService->fresh(),
        ]));

        Queue::assertNothingPushed();

        $this->assertReservationMissing($domainService);
        $this->assertReservationMissing($hostingService);

        $this->assertSame(
            Service::STATUS_PENDING,
            $domainService->fresh()->status,
        );

        $this->assertSame(
            Service::STATUS_ACTIVE,
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

        return [
            $domainService,
            $hostingService,
        ];
    }

    private function serviceRecord(
        Server $server,
        User $user,
        int $orderId,
    ): Service {
        $fixture = $this->createProduct([
            'server_id' => $server->id,
        ]);

        return Service::factory()->create([
            'user_id' => $user->id,
            'order_id' => $orderId,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_PENDING,
            'expires_at' => null,
        ]);
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

    private function reserve(Service $service): void
    {
        $service->properties()->updateOrCreate(
            [
                'key' => LinkedProvisioningRecoveryService::DISPATCH_PROPERTY,
            ],
            [
                'name' => 'Linked provisioning dispatched',
                'value' => now()->toIso8601String(),
            ],
        );
    }

    private function assertReservationExists(
        Service $service,
    ): void {
        $this->assertDatabaseHas(
            'properties',
            [
                'model_type' => Service::class,
                'model_id' => $service->id,
                'key' => LinkedProvisioningRecoveryService::DISPATCH_PROPERTY,
            ],
        );
    }

    private function assertReservationMissing(
        Service $service,
    ): void {
        $this->assertDatabaseMissing(
            'properties',
            [
                'model_type' => Service::class,
                'model_id' => $service->id,
                'key' => LinkedProvisioningRecoveryService::DISPATCH_PROPERTY,
            ],
        );
    }

    private function service(): LinkedPaidProvisioningService
    {
        return new LinkedPaidProvisioningService;
    }
}