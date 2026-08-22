<?php

namespace Tests\Unit\Extensions\Servers;

use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Mockery;
use Paymenter\Extensions\Servers\Stacker\Stacker;
use Tests\TestCase;

require_once dirname(__DIR__, 4)
    . '/extensions/Servers/Stacker/Stacker.php';

class StackerRecoveryTest extends TestCase
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

    private function service(int $writes = 4): Service
    {
        $relation = Mockery::mock();

        $relation->shouldReceive('updateOrCreate')
            ->times($writes);

        $service = Mockery::mock(Service::class)
            ->makePartial();

        $service->id = 42;
        $service->user_id = 7;

        $service->shouldReceive('properties')
            ->times($writes)
            ->andReturn($relation);

        return $service;
    }

    private function response(string $state): array
    {
        return [
            'contractVersion' => 'v1',
            'operationId' => 'operation-42',
            'billingServiceId' => '42',
            'state' => $state,
            'duplicate' => true,
            'resource' => [
                'resourceId' => null,
                'workloadType' => 'vpstack',
            ],
            'customerSafe' => [],
            'error' => null,
        ];
    }

    public function test_reconciles_when_operation_id_is_known(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            '*' => Http::response(
                $this->response('running'),
                200,
            ),
        ]);

        $response = $this->client()->recoverUnknownOutcome(
            $this->service(),
            $this->settings(),
            [
                'stacker_operation_id' => 'operation-42',
                'stacker_operation_type' => 'deploy',
                'stacker_intent_version_deploy' => '3',
            ],
            'deploy',
            'payment_confirmed',
        );

        $this->assertSame('running', $response['state']);

        Http::assertSent(fn ($request) =>
            $request->method() === 'GET'
        );
    }

    public function test_retries_with_same_key_when_id_is_unknown(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            '*' => Http::response(
                $this->response('accepted'),
                200,
            ),
        ]);

        $response = $this->client()->recoverUnknownOutcome(
            $this->service(),
            $this->settings(),
            [
                'stacker_intent_version_deploy' => '3',
            ],
            'deploy',
            'payment_confirmed',
        );

        $this->assertSame('accepted', $response['state']);

        Http::assertSent(fn ($request) =>
            $request->method() === 'POST'
            && $request->data()['idempotencyKey']
                === 'vpay:42:deploy:3'
        );
    }
}
