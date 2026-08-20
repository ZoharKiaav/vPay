<?php

namespace Paymenter\Extensions\Servers\Stacker;

use App\Classes\Extension\Server;
use App\Models\Service;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class Stacker extends Server
{
    protected function shouldVerifyTls(): bool
    {
        $value = $this->config('verify_tls');

        if ($value === null || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    protected function endpoint(string $path): string
    {
        return rtrim((string) $this->config('host'), '/')
            . '/api/'
            . ltrim($path, '/');
    }

    protected function request(
        string $method,
        string $path,
        array $payload = [],
    ): array {
        $request = Http::acceptJson()
            ->withHeaders([
                'x-api-key' => (string) $this->config('api_key'),
            ])
            ->timeout((int) ($this->config('timeout') ?: 30));

        if (!$this->shouldVerifyTls()) {
            $request = $request->withoutVerifying();
        }

        $response = match (strtoupper($method)) {
            'GET' => $request->get($this->endpoint($path), $payload),
            'POST' => $request->post($this->endpoint($path), $payload),
            default => throw new Exception(
                'Unsupported Stacker HTTP method',
            ),
        };

        return $this->validatedJsonResponse($response);
    }

    protected function validatedJsonResponse(Response $response): array
    {
        if (!$response->successful()) {
            throw new Exception(
                'Stacker API request failed with HTTP '
                . $response->status(),
            );
        }

        $body = $response->json();

        if (!is_array($body)) {
            throw new Exception(
                'Stacker API returned an invalid JSON response',
            );
        }

        return $body;
    }

    public function acceptProvisioning(array $payload): array
    {
        return $this->request(
            'POST',
            'vkloud/provisioning/v1/accept',
            $payload,
        );
    }

    public function operationStatus(string $operationId): array
    {
        return $this->request(
            'GET',
            'vkloud/provisioning/v1/operations/'
                . rawurlencode($operationId),
        );
    }

    public function serviceStatus(string $billingServiceId): array
    {
        return $this->request(
            'GET',
            'vkloud/provisioning/v1/services/'
                . rawurlencode($billingServiceId),
        );
    }

    public function billingCustomerId(Service $service): string
    {
        if (empty($service->user_id)) {
            throw new Exception(
                'vPay service has no billing customer',
            );
        }

        return (string) $service->user_id;
    }

    public function billingServiceId(Service $service): string
    {
        if (empty($service->id)) {
            throw new Exception(
                'vPay service has no stable service ID',
            );
        }

        return (string) $service->id;
    }

    public function intentVersionPropertyKey(
        string $operation,
    ): string {
        $allowedOperations = [
            'deploy',
            'suspend',
            'unsuspend',
            'terminate',
            'rotate_credentials',
            'health_check',
        ];

        if (!in_array($operation, $allowedOperations, true)) {
            throw new Exception(
                'Unsupported Stacker intent operation',
            );
        }

        return 'stacker_intent_version_' . $operation;
    }

    public function currentIntentVersion(
        array $properties,
        string $operation,
    ): int {
        $key = $this->intentVersionPropertyKey($operation);
        $version = (int) ($properties[$key] ?? 1);

        return max(1, $version);
    }

    public function nextIntentVersion(
        array $properties,
        string $operation,
    ): int {
        return $this->currentIntentVersion(
            $properties,
            $operation,
        ) + 1;
    }
    public function buildServicePropertyUpdates(
        array $response,
        string $operation,
        int $intentVersion,
    ): array {
        if (empty($response['operationId'])) {
            throw new Exception(
                'Stacker response has no operation ID',
            );
        }

        $allowedStates = [
            'accepted',
            'running',
            'succeeded',
            'failed',
            'cancelled',
        ];

        if (
            empty($response['state'])
            || !in_array($response['state'], $allowedStates, true)
        ) {
            throw new Exception(
                'Stacker response has an invalid operation state',
            );
        }

        $updates = [
            'stacker_operation_id' => [
                'name' => 'Stacker operation ID',
                'value' => (string) $response['operationId'],
            ],
            'vkloud_service_state' => [
                'name' => 'vKloud service state',
                'value' => (string) $response['state'],
            ],
            $this->intentVersionPropertyKey($operation) => [
                'name' => 'Stacker intent version: ' . $operation,
                'value' => (string) $intentVersion,
            ],
        ];

        $resourceId = $response['resource']['resourceId'] ?? null;

        if (is_string($resourceId) && $resourceId !== '') {
            $updates['stacker_resource_id'] = [
                'name' => 'Stacker resource ID',
                'value' => $resourceId,
            ];
        }

        return $updates;
    }

    public function persistProvisioningResponse(
        Service $service,
        array $response,
        string $operation,
        int $intentVersion,
    ): array {
        $updates = $this->buildServicePropertyUpdates(
            $response,
            $operation,
            $intentVersion,
        );

        foreach ($updates as $key => $property) {
            $service->properties()->updateOrCreate(
                ['key' => $key],
                $property,
            );
        }

        return $updates;
    }
    public function buildIdempotencyKey(
        string $billingServiceId,
        string $operation,
        int $intentVersion,
    ): string {
        if ($intentVersion < 1) {
            throw new Exception(
                'Intent version must be greater than zero',
            );
        }

        return sprintf(
            'vpay:%s:%s:%d',
            $billingServiceId,
            $operation,
            $intentVersion,
        );
    }

    public function buildProvisioningPayload(
        string $operation,
        string $billingCustomerId,
        string $billingServiceId,
        array $product,
        string $reason,
        int $intentVersion = 1,
        array $requestedConfiguration = [],
    ): array {
        $allowedOperations = [
            'deploy',
            'status',
            'suspend',
            'unsuspend',
            'terminate',
            'rotate_credentials',
            'health_check',
        ];

        $allowedReasons = [
            'payment_confirmed',
            'admin_action',
            'overdue',
            'payment_restored',
            'cancellation',
            'customer_action',
        ];

        if (!in_array($operation, $allowedOperations, true)) {
            throw new Exception(
                'Unsupported Stacker provisioning operation',
            );
        }

        if (!in_array($reason, $allowedReasons, true)) {
            throw new Exception(
                'Unsupported Stacker provisioning reason',
            );
        }

        foreach ([
            'workload_type',
            'product_id',
            'template_id',
            'template_version',
        ] as $requiredKey) {
            if (empty($product[$requiredKey])) {
                throw new Exception(
                    'Missing Stacker product setting: '
                    . $requiredKey,
                );
            }
        }

        return [
            'contractVersion' => 'v1',
            'operation' => $operation,
            'idempotencyKey' => $this->buildIdempotencyKey(
                $billingServiceId,
                $operation,
                $intentVersion,
            ),
            'billing' => [
                'customerId' => $billingCustomerId,
                'serviceId' => $billingServiceId,
            ],
            'product' => [
                'workloadType' => $product['workload_type'],
                'productId' => $product['product_id'],
                'templateId' => $product['template_id'],
                'templateVersion' => $product['template_version'],
            ],
            'requestedConfiguration' => $requestedConfiguration,
            'context' => [
                'requestedBy' => 'vpay',
                'reason' => $reason,
            ],
        ];
    }
    public function createServer(
        Service $service,
        $settings,
        $properties,
    ): array {
        $intentVersion = $this->currentIntentVersion(
            $properties,
            'deploy',
        );

        $payload = $this->buildProvisioningPayload(
            'deploy',
            $this->billingCustomerId($service),
            $this->billingServiceId($service),
            $settings,
            'payment_confirmed',
            $intentVersion,
        );

        $response = $this->acceptProvisioning($payload);

        $this->persistProvisioningResponse(
            $service,
            $response,
            'deploy',
            $intentVersion,
        );

        return [
            'operation_id' => $response['operationId'],
            'service_state' => $response['state'],
            'resource_id' =>
                $response['resource']['resourceId'] ?? null,
            'duplicate' => (bool) (
                $response['duplicate'] ?? false
            ),
        ];
    }
    protected function performLifecycleOperation(
        Service $service,
        array $settings,
        array $properties,
        string $operation,
        string $reason,
    ): bool {
        $intentVersion = $this->currentIntentVersion(
            $properties,
            $operation,
        );

        $payload = $this->buildProvisioningPayload(
            $operation,
            $this->billingCustomerId($service),
            $this->billingServiceId($service),
            $settings,
            $reason,
            $intentVersion,
        );

        $response = $this->acceptProvisioning($payload);

        $this->persistProvisioningResponse(
            $service,
            $response,
            $operation,
            $intentVersion,
        );

        return true;
    }

    public function suspendServer(
        Service $service,
        $settings,
        $properties,
    ): bool {
        return $this->performLifecycleOperation(
            $service,
            $settings,
            $properties,
            'suspend',
            'overdue',
        );
    }

    public function unsuspendServer(
        Service $service,
        $settings,
        $properties,
    ): bool {
        return $this->performLifecycleOperation(
            $service,
            $settings,
            $properties,
            'unsuspend',
            'payment_restored',
        );
    }

    public function terminateServer(
        Service $service,
        $settings,
        $properties,
    ): bool {
        return $this->performLifecycleOperation(
            $service,
            $settings,
            $properties,
            'terminate',
            'cancellation',
        );
    }
    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'host',
                'type' => 'text',
                'label' => 'Stacker URL',
                'placeholder' => 'https://stacker.example.com',
                'validation' => 'url:http,https',
                'required' => true,
            ],
            [
                'name' => 'api_key',
                'type' => 'password',
                'label' => 'Dedicated vPay provisioning API key',
                'required' => true,
                'encrypted' => true,
            ],
            [
                'name' => 'verify_tls',
                'type' => 'checkbox',
                'label' => 'Verify TLS certificate',
                'default' => true,
                'required' => false,
            ],
            [
                'name' => 'timeout',
                'type' => 'number',
                'label' => 'API timeout',
                'default' => 30,
                'required' => false,
                'min_value' => 5,
                'suffix' => 'seconds',
            ],
        ];
    }

    public function getProductConfig($values = []): array
    {
        return [
            [
                'name' => 'workload_type',
                'type' => 'select',
                'label' => 'vKloud workload type',
                'required' => true,
                'options' => [
                    [
                        'label' => 'SaaS',
                        'value' => 'saas',
                    ],
                    [
                        'label' => 'VPStack',
                        'value' => 'vpstack',
                    ],
                ],
            ],
            [
                'name' => 'product_id',
                'type' => 'text',
                'label' => 'vKloud product ID',
                'required' => true,
            ],
            [
                'name' => 'template_id',
                'type' => 'text',
                'label' => 'Stacker template ID',
                'required' => true,
            ],
            [
                'name' => 'template_version',
                'type' => 'text',
                'label' => 'Immutable template version',
                'required' => true,
            ],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $this->serviceStatus('__vpay_connection_test__');

            return true;
        } catch (Exception $exception) {
            if (
                $exception->getMessage()
                === 'Stacker API request failed with HTTP 404'
            ) {
                return true;
            }

            return $exception->getMessage();
        }
    }
}
