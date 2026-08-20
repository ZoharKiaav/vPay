<?php

namespace Tests\Unit\Extensions\Servers;

use App\Models\Service;
use Exception;
use Mockery;
use Paymenter\Extensions\Servers\Stacker\Stacker;
use Tests\TestCase;

require_once dirname(__DIR__, 4)
    . '/extensions/Servers/Stacker/Stacker.php';

class StackerPersistenceTest extends TestCase
{
    private function client(): Stacker
    {
        return new Stacker([]);
    }

    private function response(
        ?string $resourceId = 'compose-7',
    ): array {
        return [
            'operationId' => 'operation-42',
            'state' => 'accepted',
            'resource' => [
                'resourceId' => $resourceId,
                'workloadType' => 'vpstack',
            ],
        ];
    }

    public function test_builds_safe_service_property_updates(): void
    {
        $updates = $this->client()
            ->buildServicePropertyUpdates(
                $this->response(),
                'deploy',
                2,
            );

        $this->assertSame(
            'operation-42',
            $updates['stacker_operation_id']['value'],
        );

        $this->assertSame(
            'compose-7',
            $updates['stacker_resource_id']['value'],
        );

        $this->assertSame(
            'accepted',
            $updates['stacker_operation_state']['value'],
        );

        $this->assertSame(
            '2',
            $updates['stacker_intent_version_deploy']['value'],
        );
    }

    public function test_does_not_overwrite_an_unassigned_resource(): void
    {
        $updates = $this->client()
            ->buildServicePropertyUpdates(
                $this->response(null),
                'deploy',
                1,
            );

        $this->assertArrayNotHasKey(
            'stacker_resource_id',
            $updates,
        );
    }

    public function test_persists_each_generated_property(): void
    {
        $relation = Mockery::mock();

        $relation->shouldReceive('updateOrCreate')
            ->times(4);

        $service = Mockery::mock(Service::class)
            ->makePartial();

        $service->shouldReceive('properties')
            ->times(4)
            ->andReturn($relation);

        $updates = $this->client()
            ->persistProvisioningResponse(
                $service,
                $this->response(),
                'deploy',
                1,
            );

        $this->assertCount(4, $updates);
    }

    public function test_rejects_a_response_without_an_operation_id(): void
    {
        $response = $this->response();
        unset($response['operationId']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            'Stacker response has no operation ID',
        );

        $this->client()->buildServicePropertyUpdates(
            $response,
            'deploy',
            1,
        );
    }

    public function test_rejects_an_invalid_operation_state(): void
    {
        $response = $this->response();
        $response['state'] = 'mysterious';

        $this->expectExceptionMessage(
            'Stacker response has an invalid operation state',
        );

        $this->client()->buildServicePropertyUpdates(
            $response,
            'deploy',
            1,
        );
    }
}
