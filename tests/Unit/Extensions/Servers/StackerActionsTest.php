<?php

namespace Tests\Unit\Extensions\Servers;

use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Paymenter\Extensions\Servers\Stacker\Stacker;
use Tests\TestCase;

require_once dirname(__DIR__, 4)
    . '/extensions/Servers/Stacker/Stacker.php';

class StackerActionsTest extends TestCase
{
    private function client(): Stacker
    {
        return new Stacker([]);
    }

    private function service(): Service
    {
        $service = new Service();
        $service->id = 42;

        return $service;
    }

    public function test_builds_customer_safe_actions(): void
    {
        Http::preventStrayRequests();

        $actions = $this->client()->getActions(
            $this->service(),
            [],
            [
                'vkloud_display_name' => 'Client Operations',
                'vkloud_service_state' => 'active',
                'vkloud_health_status' => 'healthy',
                'vkloud_support_reference' => 'support-42',
                'vkloud_primary_url' =>
                    'https://client.example.test',
            ],
        );

        $this->assertCount(5, $actions);

        $this->assertSame(
            'Client Operations',
            $actions[0]['text'],
        );

        $this->assertSame('active', $actions[1]['text']);
        $this->assertSame('healthy', $actions[2]['text']);

        $this->assertSame(
            'https://client.example.test',
            $actions[4]['url'],
        );

        Http::assertNothingSent();
    }

    public function test_omits_missing_optional_actions(): void
    {
        $actions = $this->client()->getActions(
            $this->service(),
            [],
            [
                'vkloud_service_state' => 'provisioning',
            ],
        );

        $this->assertCount(1, $actions);
        $this->assertSame('Status', $actions[0]['label']);
    }

    public function test_rejects_unsafe_url_schemes(): void
    {
        $actions = $this->client()->getActions(
            $this->service(),
            [],
            [
                'vkloud_service_state' => 'active',
                'vkloud_primary_url' =>
                    'javascript:alert(1)',
            ],
        );

        $this->assertCount(1, $actions);
        $this->assertSame('Status', $actions[0]['label']);
    }

    public function test_does_not_expose_internal_properties(): void
    {
        $actions = $this->client()->getActions(
            $this->service(),
            [],
            [
                'vkloud_service_state' => 'active',
                'stacker_operation_id' => 'operation-42',
                'stacker_resource_id' => 'compose-7',
                'private_environment' => 'secret',
            ],
        );

        $serialized = json_encode($actions);

        $this->assertStringNotContainsString(
            'operation-42',
            $serialized,
        );

        $this->assertStringNotContainsString(
            'compose-7',
            $serialized,
        );

        $this->assertStringNotContainsString(
            'secret',
            $serialized,
        );
    }
}
