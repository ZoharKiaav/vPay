<?php

namespace Tests\Unit\Extensions\Servers;

use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Mockery;
use Paymenter\Extensions\Servers\Stacker\Stacker;
use Tests\TestCase;

require_once dirname(__DIR__, 4)
    . '/extensions/Servers/Stacker/Stacker.php';

class StackerLifecycleTest extends TestCase
{
    private function client(): Stacker
    {
        return new Stacker([
            'host' => 'https://stacker.example.test',
            'api_key' => 'dedicated-vpay-key',
            'verify_tls' => true,
            'timeout' => 30,
        ]);
    }

    private function settings(): array
    {
        return [
            'workload_type' => 'vpstack',
            'product_id' => 'clientops',
            'template_id' => 'clientops-starter',
            'template_version' => '1.0.0',
        ];
    }

    public function test_create_server_accepts_and_persists_deploy(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'https://stacker.example.test/api/vkloud/provisioning/v1/accept' =>
                Http::response([
                    'contractVersion' => 'v1',
                    'operationId' => 'operation-42',
                    'billingServiceId' => '42',
                    'state' => 'accepted',
                    'duplicate' => false,
                    'resource' => [
                        'resourceId' => null,
                        'workloadType' => 'vpstack',
                    ],
                    'customerSafe' => [],
                    'error' => null,
                ], 200),
        ]);

        $relation = Mockery::mock();

        $relation->shouldReceive('updateOrCreate')
            ->times(4);

        $service = Mockery::mock(Service::class)
            ->makePartial();

        $service->id = 42;
        $service->user_id = 7;

        $service->shouldReceive('properties')
            ->times(4)
            ->andReturn($relation);

        $result = $this->client()->createServer(
            $service,
            $this->settings(),
            [],
        );

        $this->assertSame(
            'operation-42',
            $result['operation_id'],
        );
        $this->assertSame(
            'accepted',
            $result['service_state'],
        );
        $this->assertNull($result['resource_id']);
        $this->assertFalse($result['duplicate']);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return
                $request->method() === 'POST'
                && $request->hasHeader(
                    'x-api-key',
                    'dedicated-vpay-key',
                )
                && $payload['operation'] === 'deploy'
                && $payload['billing']['customerId'] === '7'
                && $payload['billing']['serviceId'] === '42'
                && $payload['idempotencyKey']
                    === 'vpay:42:deploy:1'
                && $payload['context']['reason']
                    === 'payment_confirmed';
        });
    }

    public function test_create_server_reuses_persisted_intent(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            '*' => Http::response([
                'contractVersion' => 'v1',
                'operationId' => 'operation-existing',
                'billingServiceId' => '42',
                'state' => 'accepted',
                'duplicate' => true,
                'resource' => [
                    'resourceId' => 'compose-7',
                    'workloadType' => 'vpstack',
                ],
                'customerSafe' => [],
                'error' => null,
            ], 200),
        ]);

        $relation = Mockery::mock();

        $relation->shouldReceive('updateOrCreate')
            ->times(5);

        $service = Mockery::mock(Service::class)
            ->makePartial();

        $service->id = 42;
        $service->user_id = 7;

        $service->shouldReceive('properties')
            ->times(5)
            ->andReturn($relation);

        $result = $this->client()->createServer(
            $service,
            $this->settings(),
            [
                'stacker_intent_version_deploy' => '3',
            ],
        );

        $this->assertTrue($result['duplicate']);
        $this->assertSame(
            'compose-7',
            $result['resource_id'],
        );

        Http::assertSent(fn ($request) =>
            $request->data()['idempotencyKey']
                === 'vpay:42:deploy:3'
        );
    }
}
