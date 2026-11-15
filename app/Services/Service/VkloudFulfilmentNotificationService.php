<?php

namespace App\Services\Service;

use App\Helpers\NotificationHelper;
use App\Models\Service;
use Exception;
use Illuminate\Support\Facades\DB;
use Throwable;

class VkloudFulfilmentNotificationService
{
    public const PROPERTY_KEY =
        'vkloud_fulfilment_notification_state';

    public const CLAIM_TIMEOUT_MINUTES = 15;

    public function notificationData(Service $service): array
    {
        $properties = $service->properties
            ->pluck('value', 'key')
            ->all();

        if (($properties['stacker_operation_type'] ?? null) !== 'deploy') {
            throw new Exception(
                'Fulfilment notification requires a deploy operation',
            );
        }

        if (($properties['stacker_operation_state'] ?? null) !== 'succeeded') {
            throw new Exception(
                'Fulfilment notification requires a succeeded operation',
            );
        }

        if (($properties['vkloud_service_state'] ?? null) !== 'active') {
            throw new Exception(
                'Fulfilment notification requires an active service',
            );
        }

        $encodedEndpoints = $properties['vkloud_endpoints'] ?? null;

        if (!is_string($encodedEndpoints) || $encodedEndpoints === '') {
            throw new Exception(
                'Fulfilment notification requires customer endpoints',
            );
        }

        try {
            $endpoints = json_decode(
                $encodedEndpoints,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (Throwable $error) {
            throw new Exception(
                'Fulfilment notification endpoints are invalid',
                previous: $error,
            );
        }

        if (!is_array($endpoints) || $endpoints === []) {
            throw new Exception(
                'Fulfilment notification requires customer endpoints',
            );
        }

        $canonical = [];
        $keys = [];
        $primaryCount = 0;

        foreach ($endpoints as $endpoint) {
            if (
                !is_array($endpoint)
                || array_diff(
                    array_keys($endpoint),
                    ['key', 'url', 'primary'],
                ) !== []
            ) {
                throw new Exception(
                    'Fulfilment notification endpoint is invalid',
                );
            }

            $key = $endpoint['key'] ?? null;
            $url = $endpoint['url'] ?? null;
            $primary = $endpoint['primary'] ?? null;

            if (
                !is_string($key)
                || trim($key) === ''
                || isset($keys[trim($key)])
            ) {
                throw new Exception(
                    'Fulfilment notification endpoint key is invalid',
                );
            }

            if (
                !is_string($url)
                || !filter_var($url, FILTER_VALIDATE_URL)
                || !in_array(
                    parse_url($url, PHP_URL_SCHEME),
                    ['http', 'https'],
                    true,
                )
            ) {
                throw new Exception(
                    'Fulfilment notification endpoint URL is invalid',
                );
            }

            if (!is_bool($primary)) {
                throw new Exception(
                    'Fulfilment notification endpoint primary flag is invalid',
                );
            }

            $key = trim($key);
            $keys[$key] = true;

            if ($primary) {
                $primaryCount++;
            }

            $canonical[] = [
                'key' => $key,
                'label' => ucfirst(str_replace(
                    ['-', '_'],
                    ' ',
                    $key,
                )),
                'url' => $url,
                'primary' => $primary,
            ];
        }

        if ($primaryCount !== 1) {
            throw new Exception(
                'Fulfilment notification requires one primary endpoint',
            );
        }

        usort(
            $canonical,
            fn (array $left, array $right): int =>
                $left['key'] <=> $right['key'],
        );

        return [
            'service' => $service,
            'endpoints' => $canonical,
            'primaryEndpoint' => collect($canonical)
                ->firstWhere('primary', true),
        ];
    }

    public function dispatchIfEligible(Service $service): bool
    {
        $claim = DB::transaction(function () use ($service): bool {
            $locked = Service::query()
                ->whereKey($service->getKey())
                ->with(['properties', 'user', 'product'])
                ->lockForUpdate()
                ->firstOrFail();

            $data = $this->notificationData($locked);

            $marker = $locked->properties
                ->firstWhere('key', self::PROPERTY_KEY);

            if ($marker) {
                $state = json_decode(
                    (string) $marker->value,
                    true,
                );

                if (($state['state'] ?? null) === 'sent') {
                    return false;
                }

                $claimedAt = $state['claimed_at'] ?? null;

                if (
                    ($state['state'] ?? null) === 'sending'
                    && is_string($claimedAt)
                    && now()->diffInMinutes(
                        $claimedAt,
                        absolute: true,
                    ) < self::CLAIM_TIMEOUT_MINUTES
                ) {
                    return false;
                }
            }

            $locked->properties()->updateOrCreate(
                ['key' => self::PROPERTY_KEY],
                [
                    'name' =>
                        'vKloud fulfilment notification state',
                    'value' => json_encode(
                        [
                            'state' => 'sending',
                            'claimed_at' => now()->toIso8601String(),
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                ],
            );

            return $data !== [];
        });

        if (!$claim) {
            return false;
        }

        $fresh = $service->fresh([
            'properties',
            'user',
            'product',
        ]);

        try {
            $this->sendNotification(
                $fresh,
                $this->notificationData($fresh),
            );
        } catch (Throwable $error) {
            $fresh->properties()
                ->where('key', self::PROPERTY_KEY)
                ->delete();

            throw $error;
        }

        $fresh->properties()->updateOrCreate(
            ['key' => self::PROPERTY_KEY],
            [
                'name' => 'vKloud fulfilment notification state',
                'value' => json_encode(
                    [
                        'state' => 'sent',
                        'sent_at' => now()->toIso8601String(),
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ],
        );

        return true;
    }

    protected function sendNotification(
        Service $service,
        array $data,
    ): void {
        NotificationHelper::vkloudServiceFulfilledNotification(
            $service->user,
            $service,
            $data,
        );
    }}