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

class StackerServiceStatusTest extends TestCase
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

    private function response(): array
    {
        return [
            'contractVersion' => 'v1',
            'billingServiceId' => '42',
            'workloadType' => 'vpstack',
            'state' => 'active',
            'resources' => [],
            'customerSafe' => [
                'primaryUrl' => 'https://client.example.test',
                'displayName' => 'Client Operations',
                'healthStatus' => 'healthy',
                'supportReference' => 'support-42',
            ],
        ];
    }

    public function test_maps_only_approved_customer_safe_fields(): void
    {
        $response = $this->response();
        $response['customerSafe']['internalIp'] = '10.0.0.7';

        $updates = $this->client()
            ->buildServiceStatusPropertyUpdates($response);

        $this->assertSame(
            'active',
            $updates['vkloud_service_state']['value'],
        );
        $this->assertSame(
            'https://client.example.test',
            $updates['vkloud_primary_url']['value'],
        );
        $this->assertArrayNotHasKey('internalIp', $updates);
    }

    public function test_omits_empty_optional_fields(): void
    {
        $response = $this->response();
        $response['customerSafe']['primaryUrl'] = '';

        $updates = $this->client()
            ->buildServiceStatusPropertyUpdates($response);

        $this->assertArrayNotHasKey(
            'vkloud_primary_url',
            $updates,
        );
    }

    public function test_rejects_an_invalid_service_state(): void
    {
        $response = $this->response();
        $response['state'] = 'mysterious';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            'Stacker service status has an invalid state',
        );

        $this->client()
            ->buildServiceStatusPropertyUpdates($response);
    }

    public function test_refreshes_and_persists_service_status(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            '*' => Http::response($this->response(), 200),
        ]);

        $relation = Mockery::mock();

        $relation->shouldReceive('updateOrCreate')
            ->times(5);

        $service = Mockery::mock(Service::class)
            ->makePartial();

        $service->id = 42;

        $service->shouldReceive('properties')
            ->times(5)
            ->andReturn($relation);

        $response = $this->client()
            ->refreshServiceStatus($service);

        $this->assertSame('active', $response['state']);

        Http::assertSent(fn ($request) =>
            $request->method() === 'GET'
            && str_ends_with(
                $request->url(),
                '/vkloud/provisioning/v1/services/42',
            )
        );
    }
}
