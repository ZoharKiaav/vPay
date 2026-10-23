<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class HostingDomainServiceLink extends Model
{
    protected $fillable = [
        'hosting_service_id',
        'domain_service_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (
            HostingDomainServiceLink $link,
        ): void {
            $link->validateServices();
        });
    }

    public function hostingService(): BelongsTo
    {
        return $this->belongsTo(
            Service::class,
            'hosting_service_id',
        );
    }

    public function domainService(): BelongsTo
    {
        return $this->belongsTo(
            Service::class,
            'domain_service_id',
        );
    }

    private function validateServices(): void
    {
        if (
            !$this->hosting_service_id
            || !$this->domain_service_id
        ) {
            throw new LogicException(
                'Both hosting and domain services are required.',
            );
        }

        if (
            (int) $this->hosting_service_id
            === (int) $this->domain_service_id
        ) {
            throw new LogicException(
                'A service cannot be linked to itself.',
            );
        }

        $hostingService = Service::query()->find(
            $this->hosting_service_id,
        );

        $domainService = Service::query()->find(
            $this->domain_service_id,
        );

        if ($hostingService === null || $domainService === null) {
            throw new LogicException(
                'Both linked services must exist.',
            );
        }

        if (
            (int) $hostingService->user_id
            !== (int) $domainService->user_id
        ) {
            throw new LogicException(
                'Linked services must belong to the same user.',
            );
        }

        if (
            $hostingService->order_id === null
            || $domainService->order_id === null
            || (int) $hostingService->order_id
                !== (int) $domainService->order_id
        ) {
            throw new LogicException(
                'Linked services must belong to the same order.',
            );
        }
    }
}
