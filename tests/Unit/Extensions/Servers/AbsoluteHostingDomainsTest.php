<?php

namespace Tests\Unit\Extensions\Servers;

use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Paymenter\Extensions\Servers\AbsoluteHostingDomains\AbsoluteHostingDomains;
use stdClass;
use Tests\TestCase;

class AbsoluteHostingDomainsTest extends TestCase
{
    public function test_it_prepares_a_registration_order_context(): void
    {
        $context = $this->extension()->prepareDomainOrder(
            $this->service(),
            $this->domainProperties(),
        );

        $this->assertSame('register', $context['action']);
        $this->assertSame(
            'example',
            $context['service_properties']['domain_sld'],
        );
        $this->assertSame(
            '.co.za',
            $context['service_properties']['domain_tld'],
        );
        $this->assertSame([
            'first_name' => 'Test',
            'last_name' => 'Registrant',
            'email' => 'registrant@example.test',
        ], $context['customer']);
        $this->assertSame(
            '+27.100000000',
            $context['customer_properties']['phone'],
        );
        $this->assertSame(
            '1 Example Road',
            $context['customer_properties']['address'],
        );
    }

    public function test_it_prepares_a_transfer_order_context(): void
    {
        $properties = $this->domainProperties();
        $properties['domain_action'] = 'transfer';
        $properties['epp_code'] = ' TEST-EPP-CODE ';

        $context = $this->extension()->prepareDomainOrder(
            $this->service(),
            $properties,
        );

        $this->assertSame('transfer', $context['action']);
        $this->assertSame(
            'TEST-EPP-CODE',
            $context['service_properties']['epp_code'],
        );
    }

    public function test_it_does_not_prepare_without_loaded_customer_data(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'The service customer relationship must be loaded before preparing a domain order.',
        );

        $this->extension()->prepareDomainOrder(
            new Service,
            $this->domainProperties(),
        );
    }

    public function test_it_exposes_provider_configuration(): void
    {
        $config = $this->extension()->getConfig();

        $this->assertSame(
            ['api_endpoint', 'api_username', 'api_key'],
            array_column($config, 'name'),
        );

        $this->assertTrue($config[1]['encrypted']);
        $this->assertTrue($config[2]['encrypted']);
    }

    public function test_it_exposes_domain_product_configuration(): void
    {
        $config = $this->extension()->getProductConfig();

        $this->assertSame([
            'markup_percentage',
            'markup_fixed',
            'default_nameserver_1',
            'default_nameserver_2',
            'registration_enabled',
            'transfer_enabled',
        ], array_column($config, 'name'));
    }

    public function test_it_calculates_retail_checkout_price(): void
    {
        $this->fakePricing();

        $price = $this->extension()->checkoutPrice(
            [
                'domain_action' => 'register',
                'domain_tld' => '.co.za',
                'registration_period' => '2',
            ],
            [
                'markup_percentage' => '25',
                'markup_fixed' => '5',
            ],
        );

        $this->assertSame(242.50, $price);
    }

    public function test_it_builds_domain_checkout_configuration(): void
    {
        $this->fakePricing();

        $config = $this->extension()->getCheckoutConfig(
            new Product,
            [
                'domain_action' => 'register',
                'domain_tld' => '.co.za',
                'registration_period' => '2',
            ],
        );

        $this->assertSame(
            [
                'domain_action',
                'domain_sld',
                'domain_tld',
                'registration_period',
                'nameserver_1',
                'nameserver_2',
                'nameserver_3',
                'nameserver_4',
                'nameserver_5',
                'epp_code',
            ],
            array_column($config, 'name'),
        );

        $this->assertSame(
            'register',
            $config[0]['default'],
        );

        $this->assertSame(
            ['register', 'transfer'],
            array_keys($config[0]['options']),
        );

        $this->assertSame(
            ['.co.za'],
            array_keys($config[2]['options']),
        );

        $this->assertSame(
            [1, 2, 3],
            array_keys($config[3]['options']),
        );

        $this->assertSame('2', $config[3]['default']);
    }

    public function test_it_uses_retail_prices_and_default_nameservers(): void
    {
        $this->fakePricing();

        $config = $this->extension()->getCheckoutConfig(
            new Product,
            [],
            [
                'markup_percentage' => '25',
                'markup_fixed' => '5',
                'default_nameserver_1' => 'red.proniit.com',
                'default_nameserver_2' => 'yellow.proniit.com',
            ],
        );

        $configByName = collect($config)->keyBy('name');

        $this->assertSame(
            '.co.za (ZAR 121.25)',
            $configByName['domain_tld']['options']['.co.za'],
        );
        $this->assertSame(
            'red.proniit.com',
            $configByName['nameserver_1']['default'],
        );
        $this->assertSame(
            'yellow.proniit.com',
            $configByName['nameserver_2']['default'],
        );
    }

    public function test_it_exposes_nameserver_fields_for_registration(): void
    {
        $this->fakePricing();

        $config = $this->extension()->getCheckoutConfig(
            new Product,
            [
                'domain_action' => 'register',
                'domain_tld' => '.co.za',
            ],
        );

        $configByName = collect($config)->keyBy('name');

        $this->assertTrue($configByName['nameserver_1']['required']);
        $this->assertTrue($configByName['nameserver_2']['required']);
        $this->assertFalse($configByName['nameserver_3']['required']);
        $this->assertFalse($configByName['nameserver_4']['required']);
        $this->assertFalse($configByName['nameserver_5']['required']);
        $this->assertFalse($configByName['epp_code']['required']);
        $this->assertSame(
            'nullable|string|max:255',
            $configByName['epp_code']['validation'],
        );
    }

    public function test_it_requires_an_epp_code_for_transfers(): void
    {
        $this->fakePricing();

        $config = $this->extension()->getCheckoutConfig(
            new Product,
            [
                'domain_action' => 'transfer',
                'domain_tld' => '.co.za',
            ],
        );

        $configByName = collect($config)->keyBy('name');

        $this->assertTrue($configByName['epp_code']['required']);
        $this->assertSame(
            'required|string|max:255',
            $configByName['epp_code']['validation'],
        );
    }

    public function test_nameserver_validation_accepts_hostnames(): void
    {
        $this->fakePricing();

        $config = $this->extension()->getCheckoutConfig(
            new Product,
            [
                'domain_action' => 'register',
                'domain_tld' => '.co.za',
            ],
        );

        $configByName = collect($config)->keyBy('name');
        $rules = $configByName['nameserver_1']['validation'];

        $validator = validator(
            ['nameserver' => 'ns1.example.test'],
            ['nameserver' => $rules],
        );

        $this->assertFalse($validator->fails());
    }

    public function test_nameserver_validation_rejects_invalid_values(): void
    {
        $this->fakePricing();

        $config = $this->extension()->getCheckoutConfig(
            new Product,
            [
                'domain_action' => 'register',
                'domain_tld' => '.co.za',
            ],
        );

        $configByName = collect($config)->keyBy('name');
        $rules = $configByName['nameserver_1']['validation'];

        $validator = validator(
            ['nameserver' => 'not a nameserver'],
            ['nameserver' => $rules],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_it_excludes_tlds_without_registration_pricing(): void
    {
        $this->fakePricing();

        $config = $this->extension()->getCheckoutConfig(
            new Product,
        );

        $this->assertArrayHasKey(
            '.co.za',
            $config[2]['options'],
        );

        $this->assertArrayNotHasKey(
            '.ai',
            $config[2]['options'],
        );
    }

    public function test_registration_rule_accepts_available_domains(): void
    {
        $this->fakePricing();
        $this->fakeLookup(
            isRegistered: false,
            isAvailable: true,
        );

        $rule = $this->domainRule(
            action: 'register',
        );

        $messages = [];
        $rule(
            'checkoutConfig.domain_sld',
            'vkloud-example',
            function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        $this->assertSame([], $messages);
    }

    public function test_registration_rule_rejects_unavailable_domains(): void
    {
        $this->fakePricing();
        $this->fakeLookup(
            isRegistered: true,
            isAvailable: false,
        );

        $rule = $this->domainRule(
            action: 'register',
        );

        $messages = [];
        $rule(
            'checkoutConfig.domain_sld',
            'registered-example',
            function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        $this->assertSame(
            ['The selected domain is not available for registration.'],
            $messages,
        );
    }

    public function test_transfer_rule_accepts_registered_domains(): void
    {
        $this->fakePricing();
        $this->fakeLookup(
            isRegistered: true,
            isAvailable: false,
        );

        $rule = $this->domainRule(
            action: 'transfer',
        );

        $messages = [];
        $rule(
            'checkoutConfig.domain_sld',
            'registered-example',
            function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        $this->assertSame([], $messages);
    }

    public function test_transfer_rule_rejects_unregistered_domains(): void
    {
        $this->fakePricing();
        $this->fakeLookup(
            isRegistered: false,
            isAvailable: true,
        );

        $rule = $this->domainRule(
            action: 'transfer',
        );

        $messages = [];
        $rule(
            'checkoutConfig.domain_sld',
            'available-example',
            function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        $this->assertSame(
            [
                'The selected domain is not registered and cannot be transferred.',
            ],
            $messages,
        );
    }

    public function test_it_normalises_a_full_domain_before_ordering(): void
    {
        $this->fakePricing();

        $properties = $this->domainProperties();
        $properties['domain_sld'] = ' HTTPS://WWW.EXAMPLE.CO.ZA/path ';
        $properties['domain_tld'] = '.co.za';

        $context = $this->extension()->prepareDomainOrder(
            $this->service(),
            $properties,
        );

        $this->assertSame(
            'example',
            $context['service_properties']['domain_sld'],
        );
        $this->assertSame(
            '.co.za',
            $context['service_properties']['domain_tld'],
        );
    }

    public function test_it_uses_the_tld_from_full_domain_input(): void
    {
        $this->fakePricing();

        $values = $this->extension()->normaliseDomainValues([
            'domain_sld' => 'example.co.za',
            'domain_tld' => '.za',
        ]);

        $this->assertSame('example', $values['domain_sld']);
        $this->assertSame('.co.za', $values['domain_tld']);
    }

    public function test_registration_rule_accepts_a_full_domain(): void
    {
        $this->fakePricing();
        $this->fakeLookup(
            isRegistered: false,
            isAvailable: true,
        );

        $rule = $this->domainRule(
            action: 'register',
        );

        $messages = [];
        $rule(
            'checkoutConfig.domain_sld',
            'https://www.vkloud-example.co.za/path',
            function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        $this->assertSame([], $messages);
    }

    private function domainRule(string $action): \Closure
    {
        $config = $this->extension()->getCheckoutConfig(
            new Product,
            [
                'domain_action' => $action,
                'domain_tld' => '.co.za',
            ],
        );

        return $config[1]['validation'][4];
    }

    private function fakePricing(): void
    {
        Http::fake([
            'https://api.example.test/tlds/pricing' => Http::response(
                $this->pricingFixture(),
            ),
        ]);
    }

    private function pricingFixture(): array
    {
        return [
            [
                'tld' => '.co.za',
                'registrationPrice' => '93.00',
                'renewalPrice' => '93.00',
                'transferPrice' => '0.00',
                'graceFee' => '0.00',
                'graceDays' => 10,
                'redemptionDays' => 20,
                'redemptionFee' => '600.00',
                'currencyCode' => 'ZAR',
                'years' => [1, 2, 3],
            ],
            [
                'tld' => '.ai',
                'registrationPrice' => '-1.00',
                'renewalPrice' => '-1.00',
                'transferPrice' => '-1.00',
                'graceFee' => '0.00',
                'graceDays' => 30,
                'redemptionDays' => 44,
                'redemptionFee' => '5700.00',
                'currencyCode' => 'ZAR',
                'years' => [1, 2],
            ],
        ];
    }

    private function fakeLookup(
        bool $isRegistered,
        bool $isAvailable,
    ): void {
        Http::fake([
            'https://api.example.test/tlds/pricing' => Http::response(
                $this->pricingFixture(),
            ),
            'https://api.example.test/domains/lookup' => Http::response([
                [
                    'domainName' => 'example.co.za',
                    'idnDomainName' => 'example.co.za',
                    'tld' => 'co.za',
                    'sld' => 'example',
                    'status' => $isAvailable
                        ? 'available for registration'
                        : 'registered',
                    'legacyStatus' => $isAvailable
                        ? 'available'
                        : 'unavailable',
                    'isRegistered' => $isRegistered,
                    'isAvailable' => $isAvailable,
                    'isValidDomain' => true,
                    'domainErrorMessage' => '',
                    'isPremium' => false,
                ],
            ]),
        ]);
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

    private function domainProperties(): array
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

    private function extension(): AbsoluteHostingDomains
    {
        return new AbsoluteHostingDomains([
            'api_endpoint' => 'https://api.example.test',
            'api_username' => 'reseller@example.com',
            'api_key' => '1234567890QWERTYUIOPASDFGHJKL',
        ]);
    }
}
