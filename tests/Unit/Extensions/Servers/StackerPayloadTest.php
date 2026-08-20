<?php

namespace Tests\Unit\Extensions\Servers;

use Exception;
use Paymenter\Extensions\Servers\Stacker\Stacker;
use Tests\TestCase;

require_once dirname(__DIR__, 4)
    . '/extensions/Servers/Stacker/Stacker.php';

class StackerPayloadTest extends TestCase
{
    private function client(): Stacker
    {
        return new Stacker([]);
    }

    private function product(): array
    {
        return [
            'workload_type' => 'vpstack',
            'product_id' => 'clientops',
            'template_id' => 'clientops-starter',
            'template_version' => '1.0.0',
        ];
    }

    public function test_builds_the_canonical_deploy_payload(): void
    {
        $payload = $this->client()->buildProvisioningPayload(
            'deploy',
            'customer-7',
            'service-42',
            $this->product(),
            'payment_confirmed',
        );

        $this->assertSame('v1', $payload['contractVersion']);
        $this->assertSame('deploy', $payload['operation']);
        $this->assertSame(
            'vpay:service-42:deploy:1',
            $payload['idempotencyKey'],
        );
        $this->assertSame(
            'customer-7',
            $payload['billing']['customerId'],
        );
        $this->assertSame(
            'service-42',
            $payload['billing']['serviceId'],
        );
        $this->assertSame(
            'vpstack',
            $payload['product']['workloadType'],
        );
        $this->assertSame(
            'vpay',
            $payload['context']['requestedBy'],
        );
    }

    public function test_reuses_the_same_key_for_the_same_intent(): void
    {
        $client = $this->client();

        $first = $client->buildIdempotencyKey(
            'service-42',
            'deploy',
            1,
        );

        $second = $client->buildIdempotencyKey(
            'service-42',
            'deploy',
            1,
        );

        $this->assertSame($first, $second);
    }

    public function test_changes_the_key_for_a_new_intent_version(): void
    {
        $client = $this->client();

        $this->assertNotSame(
            $client->buildIdempotencyKey(
                'service-42',
                'deploy',
                1,
            ),
            $client->buildIdempotencyKey(
                'service-42',
                'deploy',
                2,
            ),
        );
    }

    public function test_rejects_an_unsupported_operation(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            'Unsupported Stacker provisioning operation',
        );

        $this->client()->buildProvisioningPayload(
            'redeploy',
            'customer-7',
            'service-42',
            $this->product(),
            'admin_action',
        );
    }

    public function test_rejects_missing_product_settings(): void
    {
        $product = $this->product();
        unset($product['template_version']);

        $this->expectExceptionMessage(
            'Missing Stacker product setting: template_version',
        );

        $this->client()->buildProvisioningPayload(
            'deploy',
            'customer-7',
            'service-42',
            $product,
            'payment_confirmed',
        );
    }
}
