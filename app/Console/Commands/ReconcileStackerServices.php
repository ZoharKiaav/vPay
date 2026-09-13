<?php

namespace App\Console\Commands;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileStackerServices extends Command
{
    protected $signature = 'stacker:reconcile
                            {--limit=100 : Maximum services to process}';

    protected $description = 'Reconcile unfinished Stacker provisioning operations';

    public function handle(): int
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

            return self::SUCCESS;
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

        $this->info(sprintf(
            'Stacker reconciliation complete: processed=%d terminal=%d failed=%d',
            $processed,
            $terminal,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
