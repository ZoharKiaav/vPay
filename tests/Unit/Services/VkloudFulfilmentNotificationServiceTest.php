<?php

namespace Tests\Unit\Services;

use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\VkloudFulfilmentNotificationService;
use Exception;
use Tests\TestCase;

class VkloudFulfilmentNotificationServiceTest extends TestCase
{
    private function service(array $properties): Service
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => Service::STATUS_ACTIVE,
        ]);

        foreach ($properties as $key => $value) {
            $service->properties()->create([
                'key' => $key,
                'name' => $key,
                'value' => $value,
            ]);
        }

        return $service->fresh([
            'properties',
            'user',
            'product',
        ]);
    }

    private function eligibleProperties(): array
    {
        return [
            'stacker_operation_type' => 'deploy',
            'stacker_operation_state' => 'succeeded',
            'vkloud_service_state' => 'active',
            'vkloud_endpoints' => json_encode([
                [
                    'key' => 'website',
                    'url' => 'https://website.example.test',
                    'primary' => true,
                ],
                [
                    'key' => 'support',
                    'url' => 'https://support.example.test',
                    'primary' => false,
                ],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    public function test_builds_customer_safe_fulfilment_data(): void
    {
        $service = $this->service(
            $this->eligibleProperties(),
        );

        $data = app(
            VkloudFulfilmentNotificationService::class,
        )->notificationData($service);

        $this->assertSame(
            'https://website.example.test',
            $data['primaryEndpoint']['url'],
        );

        $this->assertSame(
            ['support', 'website'],
            collect($data['endpoints'])
                ->pluck('key')
                ->all(),
        );
    }

    public function test_requires_successful_deploy(): void
    {
        $properties = $this->eligibleProperties();
        $properties['stacker_operation_type'] = 'suspend';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            'Fulfilment notification requires a deploy operation',
        );

        app(
            VkloudFulfilmentNotificationService::class,
        )->notificationData(
            $this->service($properties),
        );
    }

    public function test_requires_active_service_state(): void
    {
        $properties = $this->eligibleProperties();
        $properties['vkloud_service_state'] = 'provisioning';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            'Fulfilment notification requires an active service',
        );

        app(
            VkloudFulfilmentNotificationService::class,
        )->notificationData(
            $this->service($properties),
        );
    }

    public function test_rejects_invalid_endpoint_json(): void
    {
        $properties = $this->eligibleProperties();
        $properties['vkloud_endpoints'] = '{invalid-json';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            'Fulfilment notification endpoints are invalid',
        );

        app(
            VkloudFulfilmentNotificationService::class,
        )->notificationData(
            $this->service($properties),
        );
    }

    public function test_requires_exactly_one_primary_endpoint(): void
    {
        $properties = $this->eligibleProperties();
        $properties['vkloud_endpoints'] = json_encode([
            [
                'key' => 'website',
                'url' => 'https://website.example.test',
                'primary' => false,
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            'Fulfilment notification requires one primary endpoint',
        );

        app(
            VkloudFulfilmentNotificationService::class,
        )->notificationData(
            $this->service($properties),
        );
    }

    public function test_dispatches_once_and_marks_sent(): void
    {
        $service = $this->service(
            $this->eligibleProperties(),
        );

        $dispatcher = new class extends VkloudFulfilmentNotificationService
        {
            public int $sent = 0;

            protected function sendNotification(
                Service $service,
                array $data,
            ): void {
                $this->sent++;
            }
        };

        $this->assertTrue(
            $dispatcher->dispatchIfEligible($service),
        );

        $this->assertSame(1, $dispatcher->sent);

        $marker = $service->fresh()
            ->properties()
            ->where('key', $dispatcher::PROPERTY_KEY)
            ->firstOrFail();

        $state = json_decode(
            $marker->value,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('sent', $state['state']);
        $this->assertArrayHasKey('sent_at', $state);

        $this->assertFalse(
            $dispatcher->dispatchIfEligible(
                $service->fresh(['properties']),
            ),
        );

        $this->assertSame(1, $dispatcher->sent);
    }

    public function test_removes_claim_when_delivery_throws(): void
    {
        $service = $this->service(
            $this->eligibleProperties(),
        );

        $dispatcher = new class extends VkloudFulfilmentNotificationService
        {
            protected function sendNotification(
                Service $service,
                array $data,
            ): void {
                throw new Exception(
                    'Simulated notification failure',
                );
            }
        };

        try {
            $dispatcher->dispatchIfEligible($service);

            $this->fail(
                'Expected notification delivery to fail',
            );
        } catch (Exception $error) {
            $this->assertSame(
                'Simulated notification failure',
                $error->getMessage(),
            );
        }

        $this->assertFalse(
            $service->fresh()
                ->properties()
                ->where('key', $dispatcher::PROPERTY_KEY)
                ->exists(),
        );
    }

    public function test_skips_recent_sending_claim(): void
    {
        $service = $this->service(
            $this->eligibleProperties(),
        );

        $service->properties()->create([
            'key' =>
                VkloudFulfilmentNotificationService::PROPERTY_KEY,
            'name' =>
                'vKloud fulfilment notification state',
            'value' => json_encode(
                [
                    'state' => 'sending',
                    'claimed_at' => now()->toIso8601String(),
                ],
                JSON_THROW_ON_ERROR,
            ),
        ]);

        $dispatcher = new class extends VkloudFulfilmentNotificationService
        {
            public int $sent = 0;

            protected function sendNotification(
                Service $service,
                array $data,
            ): void {
                $this->sent++;
            }
        };

        $this->assertFalse(
            $dispatcher->dispatchIfEligible(
                $service->fresh(['properties']),
            ),
        );

        $this->assertSame(0, $dispatcher->sent);
    }

    public function test_reclaims_stale_sending_claim(): void
    {
        $service = $this->service(
            $this->eligibleProperties(),
        );

        $service->properties()->create([
            'key' =>
                VkloudFulfilmentNotificationService::PROPERTY_KEY,
            'name' =>
                'vKloud fulfilment notification state',
            'value' => json_encode(
                [
                    'state' => 'sending',
                    'claimed_at' => now()
                        ->subMinutes(30)
                        ->toIso8601String(),
                ],
                JSON_THROW_ON_ERROR,
            ),
        ]);

        $dispatcher = new class extends VkloudFulfilmentNotificationService
        {
            public int $sent = 0;

            protected function sendNotification(
                Service $service,
                array $data,
            ): void {
                $this->sent++;
            }
        };

        $this->assertTrue(
            $dispatcher->dispatchIfEligible(
                $service->fresh(['properties']),
            ),
        );

        $this->assertSame(1, $dispatcher->sent);
    }}