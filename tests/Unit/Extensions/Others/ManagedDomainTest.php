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

    public function test_it_builds_a_safe_submitted_operational_summary(): void
    {
        $domain = new ManagedDomain([
            'domain_name' => ' Example.CO.ZA. ',
            'tld' => '.co.za',
            'status' => ManagedDomain::STATUS_PENDING,
            'provider_status' => 'submitted',
            'registration_period' => 1,
            'nameservers' => [
                'red.proniit.com',
                'yellow.proniit.com',
                '',
            ],
            'provider_data' => [
                'operation' => 'register',
                'response' => [
                    'order_reference' => 'REGISTER-001',
                    'api_key' => 'must-not-leak',
                    'epp_code' => 'must-not-leak',
                ],
            ],
        ]);

        $summary = $domain->operationalSummary();
        $encoded = json_encode(
            $summary,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('example.co.za', $summary['domain']);
        $this->assertSame('New registration', $summary['action']);
        $this->assertSame('Pending', $summary['domain_status']);
        $this->assertSame('Submitted', $summary['provider_status']);
        $this->assertSame('1 year', $summary['period']);
        $this->assertSame(
            ['red.proniit.com', 'yellow.proniit.com'],
            $summary['nameservers'],
        );
        $this->assertSame(
            'REGISTER-001',
            $summary['provider_reference'],
        );
        $this->assertNull($summary['warning']);

        $this->assertArrayNotHasKey('contact_data', $summary);
        $this->assertArrayNotHasKey('provider_data', $summary);
        $this->assertArrayNotHasKey('response', $summary);
        $this->assertArrayNotHasKey('epp_code', $summary);

        $this->assertStringNotContainsString(
            'must-not-leak',
            $encoded,
        );
    }

    public function test_it_warns_without_exposing_uncertain_failure_details(): void
    {
        $domain = new ManagedDomain([
            'domain_name' => 'example.com',
            'tld' => '.com',
            'status' => ManagedDomain::STATUS_PENDING,
            'provider_status' => 'uncertain',
            'registration_period' => 2,
            'provider_data' => [
                'operation' => 'register',
                'exception' => 'Sensitive\Provider\Exception',
                'message' => 'Credential-bearing provider failure.',
            ],
        ]);

        $summary = $domain->operationalSummary();
        $encoded = json_encode(
            $summary,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('Uncertain', $summary['provider_status']);
        $this->assertSame('2 years', $summary['period']);
        $this->assertSame(
            'The provider result is uncertain. Verify the order before any retry.',
            $summary['warning'],
        );
        $this->assertNull($summary['provider_reference']);

        $this->assertStringNotContainsString(
            'Sensitive',
            $encoded,
        );
        $this->assertStringNotContainsString(
            'Credential-bearing',
            $encoded,
        );
    }
}
