<?php

namespace Tests\Unit\Extensions\Servers;

use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Mockery;
use Paymenter\Extensions\Servers\Stacker\Stacker;
use Tests\TestCase;

require_once dirname(__DIR__, 4)
    . '/extensions/Servers/Stacker/Stacker.php';

class StackerLifecycleActionsTest extends TestCase
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

    private function service(): Service
    {
        $relation = Mockery::mock();

        $relation->shouldReceive('updateOrCreate')
            ->times(3);

        $service = Mockery::mock(Service::class)
            ->makePartial();

        $service->id = 42;
        $service->user_id = 7;

        $service->shouldReceive('properties')
            ->times(3)
            ->andReturn($relation);

        return $service;
    }

    private function fakeAcceptedResponse(
        string $operationId,
    ): void {
        Http::preventStrayRequests();

        Http::fake([
            '*' => Http::response([
                'contractVersion' => 'v1',
                'operationId' => $operationId,
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
    }

    public function test_suspend_maps_to_overdue(): void
    {
        $this->fakeAcceptedResponse('operation-suspend');

        $result = $this->client()->suspendServer(
            $this->service(),
            $this->settings(),
            [
                'stacker_intent_version_suspend' => '2',
            ],
        );

        $this->assertTrue($result);

        Http::assertSent(fn ($request) =>
            $request->data()['operation'] === 'suspend'
            && $request->data()['context']['reason'] === 'overdue'
            && $request->data()['idempotencyKey']
                === 'vpay:42:suspend:2'
        );
    }

    public function test_unsuspend_maps_to_payment_restored(): void
    {
        $this->fakeAcceptedResponse('operation-unsuspend');

        $result = $this->client()->unsuspendServer(
            $this->service(),
            $this->settings(),
            [],
        );

        $this->assertTrue($result);

        Http::assertSent(fn ($request) =>
            $request->data()['operation'] === 'unsuspend'
            && $request->data()['context']['reason']
                === 'payment_restored'
            && $request->data()['idempotencyKey']
                === 'vpay:42:unsuspend:1'
        );
    }

    public function test_terminate_maps_to_cancellation(): void
    {
        $this->fakeAcceptedResponse('operation-terminate');

        $result = $this->client()->terminateServer(
            $this->service(),
            $this->settings(),
            [
                'stacker_intent_version_terminate' => '4',
            ],
        );

        $this->assertTrue($result);

        Http::assertSent(fn ($request) =>
            $request->data()['operation'] === 'terminate'
            && $request->data()['context']['reason']
                === 'cancellation'
            && $request->data()['idempotencyKey']
                === 'vpay:42:terminate:4'
        );
    }
}
