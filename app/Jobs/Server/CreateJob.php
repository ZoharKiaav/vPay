<?php

namespace App\Jobs\Server;

use App\Helpers\NotificationHelper;
use App\Models\Service;
use App\Services\Service\ServerProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
    ): void {
        $service = $this->service->fresh();

        if (
            $this->activateOnSuccess
            && $service->status === Service::STATUS_ACTIVE
        ) {
            return;
        }

        $data = $provisioner->create($service);

        if ($this->activateOnSuccess) {
            $service->expires_at =
                $service->calculateNextDueDate();

            $service->status = Service::STATUS_ACTIVE;
            $service->save();
        }

        if ($this->sendNotification) {
            NotificationHelper::serverCreatedNotification(
                $service->user,
                $service,
                is_array($data) ? $data : [],
            );
        }
    }
}