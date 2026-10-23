<?php

namespace Paymenter\Extensions\Others\AbsoluteHostingDomains\Services;

use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingClient;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Models\ManagedDomain;

class ManagedDomainReconciliationService
{
    public function __construct(
        private readonly AbsoluteHostingClient $client,
        private readonly DomainReconciliationService $normaliser,
    ) {}

    public function reconcile(
        ManagedDomain $managedDomain,
    ): DomainReconciliationResult {
        $result = $this->normaliser->normalise(
            $this->client->domainInformation(
                $managedDomain->domain_name,
            ),
        );

        if ($result->domain !== $managedDomain->domain_name) {
            throw new AbsoluteHostingException(
                'Absolute Hosting returned information for a different domain.',
            );
        }

        $providerData = is_array($managedDomain->provider_data)
            ? $managedDomain->provider_data
            : [];

        $providerData['reconciliation'] = $result->toArray();

        $managedDomain->status = $this->localStatus(
            $result->status,
            $managedDomain->status,
        );
        $managedDomain->expires_at = $result->expiryDate;
        $managedDomain->nameservers = $result->nameservers;

        if ($result->transferLocked !== null) {
            $managedDomain->transfer_locked =
                $result->transferLocked;
        }

        $managedDomain->provider_data = $providerData;
        $managedDomain->last_synced_at = now();
        $managedDomain->save();

        return $result;
    }

    private function localStatus(
        string $providerStatus,
        string $currentStatus,
    ): string {
        return match (strtolower(trim($providerStatus))) {
            'active', 'registered' => ManagedDomain::STATUS_ACTIVE,
            'pending', 'registration pending' => ManagedDomain::STATUS_PENDING,
            'transfer pending', 'pending transfer' => ManagedDomain::STATUS_TRANSFER_PENDING,
            'expired' => ManagedDomain::STATUS_EXPIRED,
            'redemption', 'redemption period' => ManagedDomain::STATUS_REDEMPTION,
            'cancelled', 'canceled', 'deleted' => ManagedDomain::STATUS_CANCELLED,
            default => $currentStatus,
        };
    }
}
