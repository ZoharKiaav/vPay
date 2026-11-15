<?php

namespace Tests\Feature\Console;

use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\VkloudFulfilmentNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ReconcileStackerServicesFulfilmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_retries_terminal_fulfilment_without_unfinished_operations(): void
    {
        $service = $this->eligibleService();

        $this->mock(
            VkloudFulfilmentNotificationService::class,
            function (MockInterface $mock) use ($service): void {
                $mock
                    ->shouldReceive('dispatchIfEligible')
                    ->once()
                    ->withArgs(
                        fn (Service $candidate): bool =>
                            $candidate->is($service),
                    )
                    ->andReturn(true);
            },
        );

        $this->artisan('stacker:reconcile')
            ->expectsOutput(
                'No unfinished Stacker services found.',
            )
            ->expectsOutput(
                'vKloud fulfilment notifications: candidates=1 sent=1 failed=0',
            )
            ->expectsOutput(
                'Stacker reconciliation complete: processed=0 terminal=0 failed=0',
            )
            ->assertSuccessful();
    }

    private function eligibleService(): Service
    {
        $server = Server::query()->create([
            'name' => 'Stacker fulfilment test server',
            'extension' => 'Stacker',
            'type' => 'server',
            'enabled' => true,
        ]);

        $product = Product::factory()->create([
            'name' => 'ClientOps Starter',
            'server_id' => $server->id,
        ]);

        $service = Service::factory()->create([
            'user_id' => User::factory(),
            'product_id' => $product->id,
            'status' => Service::STATUS_ACTIVE,
        ]);

        foreach ([
            'stacker_operation_id' => 'operation-1',
            'stacker_operation_type' => 'deploy',
            'stacker_operation_state' => 'succeeded',
            'vkloud_service_state' => 'active',
            'vkloud_endpoints' => json_encode(
                [
                    [
                        'key' => 'website',
                        'url' => 'https://website.example.test',
                        'primary' => true,
                    ],
                    [
                        'key' => 'support',
                        'url' => 'https://support.example.test',
                        'primary' => false,
                    ],
                ],
                JSON_THROW_ON_ERROR,
            ),
        ] as $key => $value) {
            $service->properties()->create([
                'key' => $key,
                'name' => $key,
                'value' => $value,
            ]);
        }

        return $service->fresh([
            'product.server.settings',
            'product.settings',
            'properties',
            'user',
            'configs.configOption',
            'configs.configValue',
        ]);
    }
}