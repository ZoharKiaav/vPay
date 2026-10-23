<?php

namespace Paymenter\Extensions\Others\AbsoluteHostingDomains\Services;

final readonly class DomainReconciliationResult
{
    public function __construct(
        public string $domain,
        public string $status,
        public string $expiryDate,
        public array $nameservers,
        public ?bool $transferLocked,
        public ?bool $restorable,
        public ?bool $pendingSuspension,
        public ?bool $renewBeforeExpiration,
    ) {}

    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'status' => $this->status,
            'expiry_date' => $this->expiryDate,
            'nameservers' => $this->nameservers,
            'transfer_locked' => $this->transferLocked,
            'restorable' => $this->restorable,
            'pending_suspension' => $this->pendingSuspension,
            'renew_before_expiration' => $this->renewBeforeExpiration,
        ];
    }
}
