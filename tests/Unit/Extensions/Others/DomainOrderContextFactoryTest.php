<?php

namespace Tests\Unit\Extensions\Others;

use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use LogicException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\CustomerPropertyExtractor;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainOrderContextFactory;
use PHPUnit\Framework\TestCase;
use stdClass;

class DomainOrderContextFactoryTest extends TestCase
{
    public function test_it_prepares_a_registration_context(): void
    {
        $context = $this->factory()->make(
            $this->service(),
            $this->serviceProperties(),
        );

        $this->assertSame('register', $context['action']);
        $this->assertSame(
            $this->serviceProperties(),
            $context['service_properties'],
        );
        $this->assertSame([
            'first_name' => 'Test',
            'last_name' => 'Registrant',
            'email' => 'registrant@example.test',
        ], $context['customer']);
        $this->assertSame([
            'phone' => '+27.100000000',
            'company_name' => 'Example Test',
            'country' => 'ZA',
            'address' => '1 Example Road',
            'address2' => '',
            'city' => 'Johannesburg',
            'state' => 'Gauteng',
            'zip' => '2000',
        ], $context['customer_properties']);
    }

    public function test_it_prepares_a_transfer_context(): void
    {
        $properties = $this->serviceProperties();
        $properties['domain_action'] = 'transfer';
        $properties['epp_code'] = ' TEST-EPP-CODE ';

        $context = $this->factory()->make(
            $this->service(),
            $properties,
        );

        $this->assertSame('transfer', $context['action']);
        $this->assertSame(
            'TEST-EPP-CODE',
            $context['service_properties']['epp_code'],
        );
    }

    public function test_it_requires_the_customer_relationship(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The service customer relationship must be loaded before preparing a domain order.',
        );

        $this->factory()->make(
            new Service,
            $this->serviceProperties(),
        );
    }

    public function test_it_requires_customer_properties_to_be_loaded(): void
    {
        $customer = new User([
            'first_name' => 'Test',
            'last_name' => 'Registrant',
            'email' => 'registrant@example.test',
        ]);

        $service = new Service;
        $service->setRelation('user', $customer);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The customer properties relationship must be loaded before preparing a domain order.',
        );

        $this->factory()->make(
            $service,
            $this->serviceProperties(),
        );
    }

    public function test_it_requires_all_core_service_properties(): void
    {
        $properties = $this->serviceProperties();
        unset($properties['nameserver_2']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The service property nameserver_2 is required.',
        );

        $this->factory()->make(
            $this->service(),
            $properties,
        );
    }

    public function test_it_requires_an_epp_code_for_transfers(): void
    {
        $properties = $this->serviceProperties();
        $properties['domain_action'] = 'transfer';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The service property epp_code is required for a domain transfer.',
        );

        $this->factory()->make(
            $this->service(),
            $properties,
        );
    }

    public function test_it_rejects_an_invalid_domain_action(): void
    {
        $properties = $this->serviceProperties();
        $properties['domain_action'] = 'renew';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The domain action must be register or transfer.',
        );

        $this->factory()->make(
            $this->service(),
            $properties,
        );
    }

    private function service(): Service
    {
        $customer = new User([
            'first_name' => 'Test',
            'last_name' => 'Registrant',
            'email' => 'registrant@example.test',
        ]);

        $customer->setRelation(
            'properties',
            new Collection([
                $this->property('phone', ' +27.100000000 '),
                $this->property('company_name', 'Example Test'),
                $this->property('country', 'ZA'),
                $this->property('address', '1 Example Road'),
                $this->property('address2', ''),
                $this->property('city', 'Johannesburg'),
                $this->property('state', 'Gauteng'),
                $this->property('zip', '2000'),
            ]),
        );

        $service = new Service;
        $service->setRelation('user', $customer);

        return $service;
    }

    private function serviceProperties(): array
    {
        return [
            'domain_action' => 'register',
            'domain_sld' => 'example',
            'domain_tld' => '.co.za',
            'registration_period' => '1',
            'nameserver_1' => 'ns1.example.test',
            'nameserver_2' => 'ns2.example.test',
            'nameserver_3' => '',
            'nameserver_4' => '',
            'nameserver_5' => '',
            'epp_code' => '',
        ];
    }

    private function property(
        string $key,
        mixed $value,
    ): stdClass {
        $definition = new stdClass;
        $definition->key = $key;

        $property = new stdClass;
        $property->value = $value;
        $property->parent_property = $definition;

        return $property;
    }

    private function factory(): DomainOrderContextFactory
    {
        return new DomainOrderContextFactory(
            new CustomerPropertyExtractor,
        );
    }
}
