<?php

namespace App\Services;

final readonly class HostingDomainCartPayloads
{
    public function __construct(
        public array $hosting,
        public ?array $registrar,
        public string $linkId,
    ) {}

    public function hasRegistrarItem(): bool
    {
        return $this->registrar !== null;
    }
}
