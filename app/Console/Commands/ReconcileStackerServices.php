<?php

namespace App\Console\Commands;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use App\Services\Service\VkloudFulfilmentNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileStackerServices extends Command
{
    protected $signature = 'stacker:reconcile
                            {--limit=100 : Maximum services to process}';

    protected $description = 'Reconcile unfinished Stacker provisioning operations';

    public function handle(
        VkloudFulfilmentNotificationService $fulfilment,
    ): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));

        $services = Service::query()
            ->whereHas('product.server', function ($query) {
                $query->where('extension', 'Stacker');
            })
            ->whereHas('properties', function ($query) {
                $query
                    ->where('key', 'stacker_operation_state')
                    ->whereIn('value', ['accepted', 'running']);
            })
            ->whereHas('properties', function ($query) {
                $query
                    ->where('key', 'stacker_operation_id')
                    ->whereNotNull('value')
                    ->where('value', '!=', '');
            })
            ->with([
                'product.server.settings',
                'product.settings',
                'properties',
                'configs.configOption',
                'configs.configValue',
            ])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($services->isEmpty()) {
            $this->info('No unfinished Stacker services found.');
        }

        $processed = 0;
        $terminal = 0;
        $failed = 0;

        foreach ($services as $service) {
            try {
                $response = ExtensionHelper::reconcileServer(
                    $service,
                );

                $state = $response['state'] ?? null;

                if (in_array(
                    $state,
                    ['succeeded', 'failed', 'cancelled'],
                    true,
                )) {
                    ExtensionHelper::refreshServerStatus(
                        $service->fresh([
                            'product.server.settings',
                            'product.settings',
                            'properties',
                            'configs.configOption',
                            'configs.configValue',
                        ]),
                    );

                    if ($state === 'succeeded') {
                        $finalService = $service->fresh([
                            'product.server.settings',
                            'product.settings',
                            'properties',
                            'user',
                            'configs.configOption',
                            'configs.configValue',
                        ]);

                        try {
                            $fulfilment->dispatchIfEligible(
                                $finalService,
                            );
                        } catch (Throwable $notificationError) {
                            if (
                                $notificationError->getMessage()
                                !== 'Fulfilment notification requires a deploy operation'
                            ) {
                                throw $notificationError;
                            }
                        }
                    }

                    $terminal++;
                }

                $processed++;

                $this->line(sprintf(
                    'Service %s reconciled: %s',
                    $service->id,
                    is_string($state) ? $state : 'unknown',
                ));
            } catch (Throwable $error) {
                $failed++;

                Log::warning(
                    'Stacker service reconciliation failed',
                    [
                        'service_id' => $service->id,
                        'message' => $error->getMessage(),
                    ],
                );

                $this->warn(sprintf(
                    'Service %s reconciliation failed: %s',
                    $service->id,
                    $error->getMessage(),
                ));
            }
        }

        $fulfilmentRetried = 0;
        $fulfilmentSent = 0;
        $fulfilmentFailed = 0;

        $fulfilmentCandidates = Service::query()
            ->whereHas('product.server', function ($query) {
                $query->where('extension', 'Stacker');
            })
            ->whereHas('properties', function ($query) {
                $query
                    ->where('key', 'stacker_operation_type')
                    ->where('value', 'deploy');
            })
            ->whereHas('properties', function ($query) {
                $query
                    ->where('key', 'stacker_operation_state')
                    ->where('value', 'succeeded');
            })
            ->whereHas('properties', function ($query) {
                $query
                    ->where('key', 'vkloud_service_state')
                    ->where('value', 'active');
            })
            ->whereHas('properties', function ($query) {
                $query
                    ->where('key', 'vkloud_endpoints')
                    ->whereNotNull('value')
                    ->where('value', '!=', '');
            })
            ->where(function ($query) {
                $query
                    ->whereDoesntHave('properties', function ($propertyQuery) {
                        $propertyQuery->where(
                            'key',
                            VkloudFulfilmentNotificationService::PROPERTY_KEY,
                        );
                    })
                    ->orWhereHas('properties', function ($propertyQuery) {
                        $propertyQuery
                            ->where(
                                'key',
                                VkloudFulfilmentNotificationService::PROPERTY_KEY,
                            )
                            ->where(
                                'value',
                                'not like',
                                '%"state":"sent"%',
                            );
                    });
            })
            ->with([
                'product.server.settings',
                'product.settings',
                'properties',
                'user',
                'configs.configOption',
                'configs.configValue',
            ])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($fulfilmentCandidates as $service) {
            $fulfilmentRetried++;

            try {
                if ($fulfilment->dispatchIfEligible($service)) {
                    $fulfilmentSent++;
                }
            } catch (Throwable $error) {
                $fulfilmentFailed++;

                Log::warning(
                    'vKloud fulfilment notification retry failed',
                    [
                        'service_id' => $service->id,
                        'message' => $error->getMessage(),
                    ],
                );

                $this->warn(sprintf(
                    'Service %s fulfilment notification failed: %s',
                    $service->id,
                    $error->getMessage(),
                ));
            }
        }

        $this->info(sprintf(
            'vKloud fulfilment notifications: candidates=%d sent=%d failed=%d',
            $fulfilmentRetried,
            $fulfilmentSent,
            $fulfilmentFailed,
        ));

        $this->info(sprintf(
            'Stacker reconciliation complete: processed=%d terminal=%d failed=%d',
            $processed,
            $terminal,
            $failed,
        ));

        return (
            $failed > 0 || $fulfilmentFailed > 0
                ? self::FAILURE
                : self::SUCCESS
        );
    }
}
