<?php

namespace App\Jobs\Server;

use App\Helpers\NotificationHelper;
use App\Models\Service;
use App\Services\Service\LinkedProvisioningRecoveryService;
use App\Services\Service\ServerProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CreateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Service $service,
        public $sendNotification = true,
        public bool $activateOnSuccess = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        ServerProvisioningService $provisioner,
        ?LinkedProvisioningRecoveryService $recovery = null,
    ): void {
        $service = $this->service->fresh();

        if ($service === null) {
            return;
        }

        if (
            $this->activateOnSuccess
            && $service->status === Service::STATUS_ACTIVE
        ) {
            return;
        }

        $recovery ??= app(
            LinkedProvisioningRecoveryService::class,
        );

        $data = $provisioner->create($service);

        if ($this->activateOnSuccess) {
            $service->expires_at =
                $service->calculateNextDueDate();

            $service->status = Service::STATUS_ACTIVE;
            $service->save();

            $recovery->clearFailure($service);
            $recovery->completeIfPairActive($service);
        }

        if ($this->sendNotification) {
            NotificationHelper::serverCreatedNotification(
                $service->user,
                $service,
                is_array($data) ? $data : [],
            );
        }
    }

    /**
     * Record recoverable linked provisioning failures.
     */
    public function failed(Throwable $exception): void
    {
        if (!$this->activateOnSuccess) {
            return;
        }

        app(LinkedProvisioningRecoveryService::class)
            ->recordFailure(
                $this->service,
                $exception,
            );
    }
}