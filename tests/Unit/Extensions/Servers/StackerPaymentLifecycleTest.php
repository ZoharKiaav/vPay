<?php

namespace Tests\Unit\Extensions\Servers;

use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Mockery;
use Paymenter\Extensions\Servers\Stacker\Stacker;
use Tests\TestCase;

require_once dirname(__DIR__, 4)
    . '/extensions/Servers/Stacker/Stacker.php';

class StackerPaymentLifecycleTest extends TestCase
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

    private function operationResponse(
        string $operationId,
        string $state,
        ?string $resourceId = null,
    ): array {
        return [
            'contractVersion' => 'v1',
            'operationId' => $operationId,
            'billingServiceId' => '42',
            'state' => $state,
            'duplicate' => false,
            'resource' => [
                'resourceId' => $resourceId,
                'workloadType' => 'vpstack',
            ],
            'customerSafe' => [],
            'error' => null,
        ];
    }

    public function test_complete_payment_lifecycle(): void
    {
        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(
                $this->operationResponse(
                    'operation-deploy',
                    'accepted',
                ),
                200,
            )
            ->push(
                $this->operationResponse(
                    'operation-deploy',
                    'succeeded',
                    'compose-7',
                ),
                200,
            )
            ->push([
                'contractVersion' => 'v1',
                'billingServiceId' => '42',
                'workloadType' => 'vpstack',
                'state' => 'active',
                'resources' => [],
                'customerSafe' => [
                    'primaryUrl' =>
                        'https://client.example.test',
                    'displayName' => 'Client Operations',
                    'healthStatus' => 'healthy',
                    'supportReference' => 'support-42',
                ],
            ], 200)
            ->push(
                $this->operationResponse(
                    'operation-suspend',
                    'accepted',
                ),
                200,
            )
            ->push(
                $this->operationResponse(
                    'operation-unsuspend',
                    'accepted',
                ),
                200,
            )
            ->push(
                $this->operationResponse(
                    'operation-terminate',
                    'accepted',
                ),
                200,
            );

        $relation = Mockery::mock();

        $relation->shouldReceive('updateOrCreate')
            ->times(26);

        $service = Mockery::mock(Service::class)
            ->makePartial();

        $service->id = 42;
        $service->user_id = 7;

        $service->shouldReceive('properties')
            ->times(26)
            ->andReturn($relation);

        $client = $this->client();
        $settings = $this->settings();

        $deploy = $client->createServer(
            $service,
            $settings,
            [],
        );

        $this->assertSame(
            'operation-deploy',
            $deploy['operation_id'],
        );

        $reconciled = $client->reconcileOperation(
            $service,
            [
                'stacker_operation_id' => 'operation-deploy',
                'stacker_operation_type' => 'deploy',
                'stacker_intent_version_deploy' => '1',
            ],
        );

        $this->assertSame(
            'succeeded',
            $reconciled['state'],
        );

        $status = $client->refreshServiceStatus($service);

        $this->assertSame('active', $status['state']);

        $this->assertTrue(
            $client->suspendServer(
                $service,
                $settings,
                [],
            ),
        );

        $this->assertTrue(
            $client->unsuspendServer(
                $service,
                $settings,
                [],
            ),
        );

        $this->assertTrue(
            $client->terminateServer(
                $service,
                $settings,
                [],
            ),
        );

        Http::assertSentCount(6);

        Http::assertSent(fn ($request) =>
            $request->method() === 'POST'
            && ($request->data()['operation'] ?? null)
                === 'deploy'
            && ($request->data()['context']['reason'] ?? null)
                === 'payment_confirmed'
        );

        Http::assertSent(fn ($request) =>
            ($request->data()['operation'] ?? null)
                === 'suspend'
            && ($request->data()['context']['reason'] ?? null)
                === 'overdue'
        );

        Http::assertSent(fn ($request) =>
            ($request->data()['operation'] ?? null)
                === 'unsuspend'
            && ($request->data()['context']['reason'] ?? null)
                === 'payment_restored'
        );

        Http::assertSent(fn ($request) =>
            ($request->data()['operation'] ?? null)
                === 'terminate'
            && ($request->data()['context']['reason'] ?? null)
                === 'cancellation'
        );
    }
}
