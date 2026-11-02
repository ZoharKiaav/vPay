<?php

namespace Tests\Feature\Jobs\Server;

use App\Jobs\Server\CreateJob;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\ServerProvisioningService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CreateJobLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_success_activates_service_after_provider_returns(): void
    {
        Carbon::setTestNow(
            Carbon::parse('2030-11-02 12:00:00'),
        );

        $service = $this->pendingService(
            billingPeriod: 1,
            billingUnit: 'month',
        );

        $provisioner = Mockery::mock(
            ServerProvisioningService::class,
        );

        $provisioner
            ->shouldReceive('create')
            ->once()
            ->with(
                Mockery::on(
                    fn (Service $candidate): bool =>
                        $candidate->is($service),
                ),
            )
            ->andReturn([]);

        $job = new CreateJob(
            service: $service,
            sendNotification: false,
            activateOnSuccess: true,
        );

        $job->handle($provisioner);

        $service = $service->fresh();

        $this->assertSame(
            Service::STATUS_ACTIVE,
            $service->status,
        );

        $this->assertSame(
            '2030-12-02 00:00:00',
            $service->expires_at?->toDateTimeString(),
        );
    }

    public function test_two_year_registrar_term_is_preserved_on_success(): void
    {
        Carbon::setTestNow(
            Carbon::parse('2030-11-02 12:00:00'),
        );

        $service = $this->pendingService(
            billingPeriod: 1,
            billingUnit: 'year',
        );

        $service->properties()->createMany([
            [
                'key' => 'hosting_domain_link_role',
                'value' => 'registrar',
            ],
            [
                'key' => 'registration_period',
                'value' => '2',
            ],
        ]);

        $provisioner = Mockery::mock(
            ServerProvisioningService::class,
        );

        $provisioner
            ->shouldReceive('create')
            ->once()
            ->andReturn([]);

        $job = new CreateJob(
            service: $service,
            sendNotification: false,
            activateOnSuccess: true,
        );

        $job->handle($provisioner);

        $service = $service->fresh();

        $this->assertSame(
            Service::STATUS_ACTIVE,
            $service->status,
        );

        $this->assertSame(
            '2032-11-02 00:00:00',
            $service->expires_at?->toDateTimeString(),
        );
    }

    public function test_provider_failure_leaves_service_pending(): void
    {
        Carbon::setTestNow(
            Carbon::parse('2030-11-02 12:00:00'),
        );

        $service = $this->pendingService(
            billingPeriod: 1,
            billingUnit: 'month',
        );

        $provisioner = Mockery::mock(
            ServerProvisioningService::class,
        );

        $provisioner
            ->shouldReceive('create')
            ->once()
            ->andThrow(
                new RuntimeException(
                    'Fixture provider failure',
                ),
            );

        $job = new CreateJob(
            service: $service,
            sendNotification: false,
            activateOnSuccess: true,
        );

        try {
            $job->handle($provisioner);

            $this->fail(
                'Expected provider exception was not thrown.',
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Fixture provider failure',
                $exception->getMessage(),
            );
        }

        $service = $service->fresh();

        $this->assertSame(
            Service::STATUS_PENDING,
            $service->status,
        );

        $this->assertNull($service->expires_at);
    }

    public function test_active_retry_skips_provider_call(): void
    {
        $service = $this->pendingService(
            billingPeriod: 1,
            billingUnit: 'month',
        );

        $service->forceFill([
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => Carbon::parse(
                '2030-12-02 12:00:00',
            ),
        ])->save();

        $provisioner = Mockery::mock(
            ServerProvisioningService::class,
        );

        $provisioner
            ->shouldNotReceive('create');

        $job = new CreateJob(
            service: $service,
            sendNotification: false,
            activateOnSuccess: true,
        );

        $job->handle($provisioner);

        $service = $service->fresh();

        $this->assertSame(
            Service::STATUS_ACTIVE,
            $service->status,
        );

        $this->assertSame(
            '2030-12-02 00:00:00',
            $service->expires_at?->toDateTimeString(),
        );
    }

    private function pendingService(
        int $billingPeriod,
        string $billingUnit,
    ): Service {
        $user = User::factory()->create();

        $fixture = $this->createProduct();

        $fixture->plan->forceFill([
            'billing_period' => $billingPeriod,
            'billing_unit' => $billingUnit,
        ])->save();

        return Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_PENDING,
            'expires_at' => null,
        ]);
    }
}