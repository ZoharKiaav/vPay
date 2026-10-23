<?php

namespace App\Services;

use InvalidArgumentException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainNameNormalizer;

class HostingDomainSelectionService
{
    public function __construct(
        private readonly DomainNameNormalizer $normaliser =
            new DomainNameNormalizer,
    ) {}

    public function make(array $values): HostingDomainSelection
    {
        $mode = strtolower(trim((string) (
            $values['domain_mode'] ?? ''
        )));

        return match ($mode) {
            HostingDomainSelection::MODE_REGISTER => $this->registrarSelection(
                HostingDomainSelection::MODE_REGISTER,
                $values,
            ),

            HostingDomainSelection::MODE_TRANSFER => $this->registrarSelection(
                HostingDomainSelection::MODE_TRANSFER,
                $values,
            ),

            HostingDomainSelection::MODE_EXTERNAL => $this->externalSelection($values),

            default => throw new InvalidArgumentException(
                'Select how the hosting domain will be managed.',
            ),
        };
    }

    private function registrarSelection(
        string $mode,
        array $values,
    ): HostingDomainSelection {
        $selectedTld = trim((string) (
            $values['domain_tld'] ?? ''
        ));

        $normalised = $this->normaliser->normalise(
            (string) ($values['domain_sld'] ?? ''),
            [$selectedTld],
            $selectedTld,
        );

        $registrarProductId = filter_var(
            $values['registrar_product_id'] ?? null,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ],
        );

        if ($registrarProductId === false) {
            throw new InvalidArgumentException(
                'A registrar product is required for this domain choice.',
            );
        }

        $eppCode = trim((string) (
            $values['epp_code'] ?? ''
        ));

        if (
            $mode === HostingDomainSelection::MODE_TRANSFER
            && $eppCode === ''
        ) {
            throw new InvalidArgumentException(
                'A transfer authorisation code is required.',
            );
        }

        if ($mode !== HostingDomainSelection::MODE_TRANSFER) {
            $eppCode = '';
        }

        return new HostingDomainSelection(
            mode: $mode,
            domain: $normalised['sld'] . $normalised['tld'],
            registrarAction: $mode,
            sld: $normalised['sld'],
            tld: $normalised['tld'],
            eppCode: $eppCode !== '' ? $eppCode : null,
            registrarProductId: $registrarProductId,
        );
    }

    private function externalSelection(
        array $values,
    ): HostingDomainSelection {
        $domain = strtolower(rtrim(
            trim((string) ($values['domain'] ?? '')),
            '.',
        ));

        $validDomain = preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
            $domain,
        ) === 1;

        if (!$validDomain) {
            throw new InvalidArgumentException(
                'Enter a valid existing domain name.',
            );
        }

        if (
            trim((string) (
                $values['epp_code'] ?? ''
            )) !== ''
        ) {
            throw new InvalidArgumentException(
                'An externally managed domain cannot include a transfer code.',
            );
        }

        return new HostingDomainSelection(
            mode: HostingDomainSelection::MODE_EXTERNAL,
            domain: $domain,
            registrarAction: null,
            sld: null,
            tld: null,
            eppCode: null,
            registrarProductId: null,
        );
    }
}
