<?php

namespace Tests\Unit\Extensions\Servers;

use App\Models\Service;
use Exception;
use Illuminate\Support\Facades\Http;
use Mockery;
use Paymenter\Extensions\Servers\Stacker\Stacker;
use Tests\TestCase;

require_once dirname(__DIR__, 4)
    . '/extensions/Servers/Stacker/Stacker.php';

class StackerReconciliationTest extends TestCase
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

    public function test_reconciles_the_stored_operation(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            '*' => Http::response([
                'contractVersion' => 'v1',
                'operationId' => 'operation-42',
                'billingServiceId' => '42',
                'state' => 'running',
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

        $service->shouldReceive('properties')
            ->times(4)
            ->andReturn($relation);

        $response = $this->client()->reconcileOperation(
            $service,
            [
                'stacker_operation_id' => 'operation-42',
                'stacker_operation_type' => 'deploy',
                'stacker_intent_version_deploy' => '2',
            ],
        );

        $this->assertSame('running', $response['state']);

        Http::assertSent(fn ($request) =>
            $request->method() === 'GET'
            && str_ends_with(
                $request->url(),
                '/vkloud/provisioning/v1/operations/operation-42',
            )
        );
    }

    public function test_rejects_missing_operation_id(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            'Service has no Stacker operation to reconcile',
        );

        $this->client()->reconcileOperation(
            new Service(),
            [],
        );
    }

    public function test_rejects_missing_operation_type(): void
    {
        $this->expectExceptionMessage(
            'Service has no Stacker operation type',
        );

        $this->client()->reconcileOperation(
            new Service(),
            [
                'stacker_operation_id' => 'operation-42',
            ],
        );
    }
}
