<?php

namespace Tests\Unit\Extensions\Others;

use App\Models\Service;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\AbsoluteHostingDomains;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Models\ManagedDomain;
use Tests\TestCase;

class ManagedDomainTest extends TestCase
{
    public function test_it_normalises_domain_names_and_tlds(): void
    {
        $domain = new ManagedDomain([
            'domain_name' => ' Example.CO.ZA. ',
            'tld' => 'CO.ZA',
        ]);

        $this->assertSame('example.co.za', $domain->domain_name);
        $this->assertSame('.co.za', $domain->tld);
    }

    public function test_it_exposes_expected_casts(): void
    {
        $domain = new ManagedDomain;

        $this->assertSame(
            'integer',
            $domain->getCasts()['registration_period'],
        );
        $this->assertSame('date', $domain->getCasts()['expires_at']);
        $this->assertSame(
            'boolean',
            $domain->getCasts()['transfer_locked'],
        );
        $this->assertSame('array', $domain->getCasts()['nameservers']);
        $this->assertSame(
            'encrypted:array',
            $domain->getCasts()['contact_data'],
        );
        $this->assertSame('array', $domain->getCasts()['provider_data']);
        $this->assertSame(
            'datetime',
            $domain->getCasts()['last_synced_at'],
        );
    }

    public function test_it_defines_the_service_relationship(): void
    {
        $domain = new ManagedDomain;

        $this->assertInstanceOf(
            BelongsTo::class,
            $domain->service(),
        );
    }

    public function test_extension_boot_registers_service_relationship(): void
    {
        $extension = new AbsoluteHostingDomains;
        $extension->boot();

        $service = new Service;

        $this->assertInstanceOf(
            HasOne::class,
            $service->managedDomain(),
        );
    }

    public function test_it_exposes_expected_lifecycle_states(): void
    {
        $this->assertSame('pending', ManagedDomain::STATUS_PENDING);
        $this->assertSame('active', ManagedDomain::STATUS_ACTIVE);
        $this->assertSame(
            'transfer_pending',
            ManagedDomain::STATUS_TRANSFER_PENDING,
        );
        $this->assertSame('expired', ManagedDomain::STATUS_EXPIRED);
        $this->assertSame(
            'redemption',
            ManagedDomain::STATUS_REDEMPTION,
        );
        $this->assertSame('cancelled', ManagedDomain::STATUS_CANCELLED);
    }
}
