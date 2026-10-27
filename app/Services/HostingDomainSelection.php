<?php

namespace App\Services;

final readonly class HostingDomainSelection
{
    public const MODE_REGISTER = 'register';

    public const MODE_TRANSFER = 'transfer';

    public const MODE_EXTERNAL = 'external';

    public function __construct(
        public string $mode,
        public string $domain,
        public ?string $registrarAction,
        public ?string $sld,
        public ?string $tld,
        public ?string $eppCode,
        public ?int $registrarProductId,
    ) {}

    public function requiresRegistrarService(): bool
    {
        return in_array(
            $this->mode,
            [
                self::MODE_REGISTER,
                self::MODE_TRANSFER,
            ],
            true,
        );
    }

    public function isExternallyManaged(): bool
    {
        return $this->mode === self::MODE_EXTERNAL;
    }

    public function toCheckoutConfig(): array
    {
        return [
            'domain_mode' => $this->mode,
            'domain' => $this->domain,
            'domain_action' => $this->registrarAction,
            'domain_sld' => $this->sld,
            'domain_tld' => $this->tld,
            'epp_code' => $this->eppCode,
            'registrar_product_id' => $this->registrarProductId,
        ];
    }
}
