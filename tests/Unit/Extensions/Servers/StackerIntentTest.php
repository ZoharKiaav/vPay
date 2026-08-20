<?php

namespace Tests\Unit\Extensions\Servers;

use App\Models\Service;
use Exception;
use Paymenter\Extensions\Servers\Stacker\Stacker;
use Tests\TestCase;

require_once dirname(__DIR__, 4)
    . '/extensions/Servers/Stacker/Stacker.php';

class StackerIntentTest extends TestCase
{
    private function client(): Stacker
    {
        return new Stacker([]);
    }

    public function test_uses_stable_vpay_customer_and_service_ids(): void
    {
        $service = new Service([
            'user_id' => 7,
        ]);
        $service->id = 42;

        $this->assertSame(
            '7',
            $this->client()->billingCustomerId($service),
        );

        $this->assertSame(
            '42',
            $this->client()->billingServiceId($service),
        );
    }

    public function test_defaults_new_operations_to_intent_version_one(): void
    {
        $this->assertSame(
            1,
            $this->client()->currentIntentVersion([], 'deploy'),
        );
    }

    public function test_reuses_the_persisted_intent_version(): void
    {
        $this->assertSame(
            3,
            $this->client()->currentIntentVersion([
                'stacker_intent_version_suspend' => '3',
            ], 'suspend'),
        );
    }

    public function test_new_operator_intent_increments_the_version(): void
    {
        $this->assertSame(
            4,
            $this->client()->nextIntentVersion([
                'stacker_intent_version_deploy' => '3',
            ], 'deploy'),
        );
    }

    public function test_invalid_persisted_versions_fail_safe_to_one(): void
    {
        $this->assertSame(
            1,
            $this->client()->currentIntentVersion([
                'stacker_intent_version_deploy' => '0',
            ], 'deploy'),
        );
    }

    public function test_rejects_status_as_a_persisted_intent(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            'Unsupported Stacker intent operation',
        );

        $this->client()->currentIntentVersion([], 'status');
    }
}
