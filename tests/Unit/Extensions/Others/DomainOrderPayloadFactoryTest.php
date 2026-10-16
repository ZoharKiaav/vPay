<?php

namespace Tests\Unit\Extensions\Others;

use InvalidArgumentException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainOrderPayloadFactory;
use Tests\TestCase;

class DomainOrderPayloadFactoryTest extends TestCase
{
    public function test_it_builds_the_registration_payload(): void
    {
        $payload = $this->factory()->make(
            'register',
            $this->serviceProperties(),
            $this->customer(),
            $this->customerProperties(),
        );

        $this->assertSame('example.co.za', $payload['domain']);
        $this->assertSame(2, $payload['regperiod']);
        $this->assertSame(
            [
                'ns1' => 'ns1.example.test',
                'ns2' => 'ns2.example.test',
                'ns3' => '',
                'ns4' => '',
                'ns5' => '',
            ],
            $payload['nameservers'],
        );
        $this->assertArrayNotHasKey('eppcode', $payload);
        $this->assertSame(
            $this->expectedRegistrant(),
            $payload['contacts']['registrant'],
        );
    }

    public function test_it_builds_the_transfer_payload(): void
    {
        $properties = $this->serviceProperties();
        $properties['epp_code'] = 'TEST-EPP-CODE';

        $payload = $this->factory()->make(
            'transfer',
            $properties,
            $this->customer(),
            $this->customerProperties(),
        );

        $this->assertSame('example.co.za', $payload['domain']);
        $this->assertSame(
            [
                'ns1.example.test',
                'ns2.example.test',
                '',
                '',
                '',
            ],
            $payload['nameservers'],
        );
        $this->assertSame(
            'TEST-EPP-CODE',
            $payload['eppcode'],
        );
    }

    public function test_it_normalises_a_country_name_to_its_code(): void
    {
        $properties = $this->customerProperties();
        $properties['country'] = 'South Africa';

        $payload = $this->factory()->make(
            'register',
            $this->serviceProperties(),
            $this->customer(),
            $properties,
        );

        $this->assertSame(
            'ZA',
            $payload['contacts']['registrant']['country'],
        );
    }

    public function test_it_requires_all_registration_contact_fields(): void
    {
        $properties = $this->customerProperties();
        unset($properties['phone']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The customer property phone is required.',
        );

        $this->factory()->make(
            'register',
            $this->serviceProperties(),
            $this->customer(),
            $properties,
        );
    }

    public function test_it_requires_an_epp_code_for_transfers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'An EPP code is required for a domain transfer.',
        );

        $this->factory()->make(
            'transfer',
            $this->serviceProperties(),
            $this->customer(),
            $this->customerProperties(),
        );
    }

    public function test_it_rejects_an_invalid_action(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The domain action must be register or transfer.',
        );

        $this->factory()->make(
            'renew',
            $this->serviceProperties(),
            $this->customer(),
            $this->customerProperties(),
        );
    }

    private function serviceProperties(): array
    {
        return [
            'domain_sld' => 'Example',
            'domain_tld' => '.CO.ZA',
            'registration_period' => '2',
            'nameserver_1' => 'NS1.EXAMPLE.TEST.',
            'nameserver_2' => 'NS2.EXAMPLE.TEST.',
            'nameserver_3' => '',
            'nameserver_4' => '',
            'nameserver_5' => '',
        ];
    }

    private function customer(): array
    {
        return [
            'first_name' => 'Test',
            'last_name' => 'Registrant',
            'email' => 'registrant@example.test',
        ];
    }

    private function customerProperties(): array
    {
        return [
            'phone' => '+27.100000000',
            'company_name' => 'Example Test',
            'country' => 'ZA',
            'address' => '1 Example Road',
            'address2' => '',
            'city' => 'Johannesburg',
            'state' => 'Gauteng',
            'zip' => '2000',
            'tax_id' => '',
        ];
    }

    private function expectedRegistrant(): array
    {
        return [
            'firstname' => 'Test',
            'lastname' => 'Registrant',
            'companyname' => 'Example Test',
            'email' => 'registrant@example.test',
            'address1' => '1 Example Road',
            'address2' => '',
            'city' => 'Johannesburg',
            'state' => 'Gauteng',
            'postcode' => '2000',
            'country' => 'ZA',
            'phonenumber' => '+27.100000000',
            'tax_id' => '',
        ];
    }

    private function factory(): DomainOrderPayloadFactory
    {
        return new DomainOrderPayloadFactory;
    }
}
