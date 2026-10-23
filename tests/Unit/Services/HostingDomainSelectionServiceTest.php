<?php

namespace Tests\Unit\Services;

use App\Services\HostingDomainSelection;
use App\Services\HostingDomainSelectionService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class HostingDomainSelectionServiceTest extends TestCase
{
    public function test_it_builds_a_registration_selection(): void
    {
        $selection = $this->service()->make([
            'domain_mode' => 'register',
            'domain_sld' => ' Example ',
            'domain_tld' => 'co.za',
            'registrar_product_id' => 42,
            'epp_code' => 'must-be-discarded',
        ]);

        $this->assertSame(
            HostingDomainSelection::MODE_REGISTER,
            $selection->mode,
        );
        $this->assertSame('example.co.za', $selection->domain);
        $this->assertSame('register', $selection->registrarAction);
        $this->assertSame('example', $selection->sld);
        $this->assertSame('.co.za', $selection->tld);
        $this->assertNull($selection->eppCode);
        $this->assertSame(42, $selection->registrarProductId);
        $this->assertTrue(
            $selection->requiresRegistrarService(),
        );
        $this->assertFalse(
            $selection->isExternallyManaged(),
        );
    }

    public function test_it_builds_a_transfer_selection(): void
    {
        $selection = $this->service()->make([
            'domain_mode' => 'transfer',
            'domain_sld' => 'example',
            'domain_tld' => '.co.za',
            'registrar_product_id' => '42',
            'epp_code' => ' TEST-EPP-CODE ',
        ]);

        $this->assertSame(
            HostingDomainSelection::MODE_TRANSFER,
            $selection->mode,
        );
        $this->assertSame('example.co.za', $selection->domain);
        $this->assertSame('transfer', $selection->registrarAction);
        $this->assertSame('TEST-EPP-CODE', $selection->eppCode);
        $this->assertSame(42, $selection->registrarProductId);
        $this->assertTrue(
            $selection->requiresRegistrarService(),
        );
    }

    public function test_it_builds_an_external_selection(): void
    {
        $selection = $this->service()->make([
            'domain_mode' => 'external',
            'domain' => ' Existing.Example.COM. ',
        ]);

        $this->assertSame(
            HostingDomainSelection::MODE_EXTERNAL,
            $selection->mode,
        );
        $this->assertSame(
            'existing.example.com',
            $selection->domain,
        );
        $this->assertNull($selection->registrarAction);
        $this->assertNull($selection->sld);
        $this->assertNull($selection->tld);
        $this->assertNull($selection->eppCode);
        $this->assertNull($selection->registrarProductId);
        $this->assertFalse(
            $selection->requiresRegistrarService(),
        );
        $this->assertTrue(
            $selection->isExternallyManaged(),
        );

        $this->assertSame([
            'domain_mode' => 'external',
            'domain' => 'existing.example.com',
            'domain_action' => null,
            'domain_sld' => null,
            'domain_tld' => null,
            'epp_code' => null,
            'registrar_product_id' => null,
        ], $selection->toCheckoutConfig());
    }

    public function test_it_requires_a_supported_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Select how the hosting domain will be managed.',
        );

        $this->service()->make([
            'domain_mode' => 'unknown',
        ]);
    }

    public function test_managed_modes_require_a_registrar_product(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A registrar product is required for this domain choice.',
        );

        $this->service()->make([
            'domain_mode' => 'register',
            'domain_sld' => 'example',
            'domain_tld' => '.co.za',
        ]);
    }

    public function test_transfer_requires_an_epp_code(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A transfer authorisation code is required.',
        );

        $this->service()->make([
            'domain_mode' => 'transfer',
            'domain_sld' => 'example',
            'domain_tld' => '.co.za',
            'registrar_product_id' => 42,
            'epp_code' => '   ',
        ]);
    }

    public function test_external_mode_rejects_an_invalid_domain(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Enter a valid existing domain name.',
        );

        $this->service()->make([
            'domain_mode' => 'external',
            'domain' => 'not a domain',
        ]);
    }

    public function test_external_mode_rejects_an_epp_code(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'An externally managed domain cannot include a transfer code.',
        );

        $this->service()->make([
            'domain_mode' => 'external',
            'domain' => 'example.com',
            'epp_code' => 'NOT-ALLOWED',
        ]);
    }

    private function service(): HostingDomainSelectionService
    {
        return new HostingDomainSelectionService;
    }
}
