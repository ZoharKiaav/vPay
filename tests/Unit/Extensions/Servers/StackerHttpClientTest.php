<?php

namespace Tests\Unit\Extensions\Servers;

use Illuminate\Support\Facades\Http;
use Paymenter\Extensions\Servers\Stacker\Stacker;
use Tests\TestCase;

require_once dirname(__DIR__, 4)
    . '/extensions/Servers/Stacker/Stacker.php';

class StackerHttpClientTest extends TestCase
{
    private function client(array $overrides = []): Stacker
    {
        return new Stacker(array_merge([
            'host' => 'https://stacker.example.test',
            'api_key' => 'dedicated-vpay-key',
            'verify_tls' => true,
            'timeout' => 30,
        ], $overrides));
    }

    public function test_accepts_provisioning_through_versioned_json_api(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'https://stacker.example.test/api/vkloud/provisioning/v1/accept' =>
                Http::response([
                    'contractVersion' => 'v1',
                    'operationId' => 'operation-42',
                    'billingServiceId' => 'service-7',
                    'state' => 'accepted',
                ], 200),
        ]);

        $payload = [
            'contractVersion' => 'v1',
            'operation' => 'deploy',
            'idempotencyKey' => 'vpay:service-7:deploy:1',
        ];

        $response = $this->client()->acceptProvisioning($payload);

        $this->assertSame('operation-42', $response['operationId']);

        Http::assertSent(function ($request) use ($payload) {
            return
                $request->method() === 'POST'
                && $request->url()
                    === 'https://stacker.example.test/api/vkloud/provisioning/v1/accept'
                && $request->hasHeader(
                    'x-api-key',
                    'dedicated-vpay-key',
                )
                && $request->data() === $payload;
        });
    }

    public function test_reads_encoded_operation_status(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'https://stacker.example.test/api/vkloud/provisioning/v1/operations/*' =>
                Http::response([
                    'operationId' => 'operation/42',
                    'state' => 'running',
                ], 200),
        ]);

        $response = $this->client()->operationStatus(
            'operation/42',
        );

        $this->assertSame('running', $response['state']);

        Http::assertSent(fn ($request) =>
            $request->method() === 'GET'
            && $request->url()
                === 'https://stacker.example.test/api/vkloud/provisioning/v1/operations/operation%2F42'
        );
    }

    public function test_reads_encoded_service_status(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'https://stacker.example.test/api/vkloud/provisioning/v1/services/*' =>
                Http::response([
                    'billingServiceId' => 'service/7',
                    'state' => 'active',
                ], 200),
        ]);

        $response = $this->client()->serviceStatus('service/7');

        $this->assertSame('active', $response['state']);

        Http::assertSent(fn ($request) =>
            $request->method() === 'GET'
            && $request->url()
                === 'https://stacker.example.test/api/vkloud/provisioning/v1/services/service%2F7'
        );
    }

    public function test_rejects_http_errors_without_exposing_response_body(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            '*' => Http::response([
                'secret' => 'must-not-leak',
            ], 500),
        ]);

        $this->expectExceptionMessage(
            'Stacker API request failed with HTTP 500',
        );

        try {
            $this->client()->serviceStatus('service-7');
        } catch (\Exception $exception) {
            $this->assertStringNotContainsString(
                'must-not-leak',
                $exception->getMessage(),
            );

            throw $exception;
        }
    }

    public function test_rejects_non_json_object_responses(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            '*' => Http::response('not-json', 200),
        ]);

        $this->expectExceptionMessage(
            'Stacker API returned an invalid JSON response',
        );

        $this->client()->serviceStatus('service-7');
    }
}

