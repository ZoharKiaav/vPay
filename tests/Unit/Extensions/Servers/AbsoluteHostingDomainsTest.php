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
            'allow_custom_nameservers',
        ], array_column($config, 'name'));
    }

    public function test_it_returns_zero_checkout_price_until_a_domain_is_entered(): void
    {
        $price = $this->extension()->checkoutPrice(
            [
                'domain_action' => 'register',
                'domain_sld' => '',
                'domain_tld' => '.co.za',
                'registration_period' => '1',
            ],
        );

        $this->assertSame(0.0, $price);
        Http::assertNothingSent();
    }

    public function test_it_calculates_retail_checkout_price(): void
    {
        $this->fakePricing();

        $price = $this->extension()->checkoutPrice(
            [
                'domain_action' => 'register',
                'domain_sld' => 'example',
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
                'use_custom_nameservers',
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

    public function test_it_hides_default_nameservers_until_custom_nameservers_are_selected(): void
    {
        $this->fakePricing();

        $config = $this->extension()->getCheckoutConfig(
            new Product,
            [
                'domain_action' => 'register',
                'domain_tld' => '.co.za',
            ],
            [
                'default_nameserver_1' => 'red.proniit.com',
                'default_nameserver_2' => 'yellow.proniit.com',
                'allow_custom_nameservers' => true,
            ],
        );

        $configByName = collect($config)->keyBy('name');

        $this->assertTrue(
            $configByName['use_custom_nameservers']['visible'],
        );
        $this->assertFalse(
            $configByName['nameserver_1']['visible'],
        );
        $this->assertFalse(
            $configByName['nameserver_2']['visible'],
        );
        $this->assertSame(
            'red.proniit.com',
            $configByName['nameserver_1']['default'],
        );
        $this->assertSame(
            'yellow.proniit.com',
            $configByName['nameserver_2']['default'],
        );
        $this->assertFalse(
            $configByName['epp_code']['visible'],
        );
    }

    public function test_it_exposes_custom_nameservers_when_selected(): void
    {
        $this->fakePricing();

        $config = $this->extension()->getCheckoutConfig(
            new Product,
            [
                'domain_action' => 'register',
                'domain_tld' => '.co.za',
                'use_custom_nameservers' => true,
                'nameserver_1' => 'ns1.customer.test',
                'nameserver_2' => 'ns2.customer.test',
            ],
            [
                'allow_custom_nameservers' => true,
            ],
        );

        $configByName = collect($config)->keyBy('name');

        $this->assertTrue(
            $configByName['nameserver_1']['visible'],
        );
        $this->assertTrue(
            $configByName['nameserver_2']['visible'],
        );
        $this->assertTrue(
            $configByName['nameserver_3']['visible'],
        );
        $this->assertSame(
            'ns1.customer.test',
            $configByName['nameserver_1']['default'],
        );
        $this->assertSame(
            'ns2.customer.test',
            $configByName['nameserver_2']['default'],
        );
    }

    public function test_it_respects_enabled_domain_actions(): void
    {
        $this->fakePricing();

        $config = $this->extension()->getCheckoutConfig(
            new Product,
            [
                'domain_action' => 'register',
                'domain_tld' => '.co.za',
            ],
            [
                'registration_enabled' => false,
                'transfer_enabled' => true,
            ],
        );

        $this->assertSame('transfer', $config[0]['default']);
        $this->assertSame(
            ['transfer'],
            array_keys($config[0]['options']),
        );
    }

    public function test_it_rejects_products_with_all_actions_disabled(): void
    {
        $this->fakePricing();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Domain registration and transfer are both disabled for this product.',
        );

        $this->extension()->getCheckoutConfig(
            new Product,
            [],
            [
                'registration_enabled' => false,
                'transfer_enabled' => false,
            ],
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
        $this->assertTrue($configByName['epp_code']['visible']);
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

    public function test_registration_rule_hides_provider_failures(): void
    {
        $this->fakePricing();

        Http::fake([
            'https://api.example.test/tlds/pricing' => Http::response($this->pricingFixture()),
            'https://api.example.test/domains/lookup' => Http::response(
                ['message' => 'Sensitive upstream detail'],
                503,
            ),
        ]);

        $rule = $this->domainRule(
            action: 'register',
        );

        $messages = [];

        $rule(
            'checkoutConfig.domain_sld',
            'provider-error-example',
            function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        $this->assertSame(
            [
                'The domain service is temporarily unavailable. Please try again.',
            ],
            $messages,
        );

        $this->assertStringNotContainsString(
            'Sensitive upstream detail',
            implode(' ', $messages),
        );
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

    public function test_checkout_validation_accepts_a_full_domain(): void
    {
        $this->fakePricing();
        $this->fakeLookup(
            isRegistered: false,
            isAvailable: true,
        );

        $config = $this->extension()->getCheckoutConfig(
            new Product,
            [
                'domain_action' => 'register',
                'domain_tld' => '.co.za',
            ],
        );

        $configByName = collect($config)->keyBy('name');

        $validator = validator(
            [
                'domain' => 'vkloud-example.co.za',
            ],
            [
                'domain' => $configByName['domain_sld']['validation'],
            ],
        );

        $this->assertFalse(
            $validator->fails(),
            implode(' ', $validator->errors()->all()),
        );
    }

    public function test_it_synchronises_a_complete_domain_and_tld(): void
    {
        $this->fakePricing();

        $values = $this->extension()->synchroniseCheckoutConfig(
            [
                'domain_sld' => 'www.example.co.za',
                'domain_tld' => '.academy',
                'registration_period' => '1',
            ],
            'domain_sld',
        );

        $this->assertSame(
            'example',
            $values['domain_sld'],
        );
        $this->assertSame(
            '.co.za',
            $values['domain_tld'],
        );
        $this->assertSame(
            '1',
            $values['registration_period'],
        );
    }

    public function test_it_keeps_the_selected_tld_for_an_sld(): void
    {
        $this->fakePricing();

        $values = $this->extension()->synchroniseCheckoutConfig(
            [
                'domain_sld' => 'example',
                'domain_tld' => '.co.za',
                'registration_period' => '2',
            ],
            'domain_sld',
        );

        $this->assertSame(
            'example',
            $values['domain_sld'],
        );
        $this->assertSame(
            '.co.za',
            $values['domain_tld'],
        );
        $this->assertSame(
            '2',
            $values['registration_period'],
        );
    }

    public function test_it_uses_the_longest_supported_tld_when_synchronising(): void
    {
        Http::fake([
            'https://api.example.test/tlds/pricing' => Http::response([
                [
                    'tld' => '.za',
                    'registrationPrice' => '50.00',
                    'renewalPrice' => '50.00',
                    'transferPrice' => '0.00',
                    'graceFee' => '0.00',
                    'graceDays' => 10,
                    'redemptionDays' => 20,
                    'redemptionFee' => '600.00',
                    'currencyCode' => 'ZAR',
                    'years' => [1],
                ],
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
            ]),
        ]);

        $values = $this->extension()->synchroniseCheckoutConfig(
            [
                'domain_sld' => 'example.co.za',
                'domain_tld' => '.za',
                'registration_period' => '2',
            ],
            'domain_sld',
        );

        $this->assertSame(
            'example',
            $values['domain_sld'],
        );
        $this->assertSame(
            '.co.za',
            $values['domain_tld'],
        );
        $this->assertSame(
            '2',
            $values['registration_period'],
        );
    }

    public function test_it_prepares_canonical_registration_values_for_cart(): void
    {
        $this->fakePricing();
        $this->fakeLookup(
            isRegistered: false,
            isAvailable: true,
        );

        $values = $this->domainProperties();
        $values['domain_sld'] =
            'https://www.example.co.za/path';
        $values['domain_tld'] = '.za';
        $values['use_custom_nameservers'] = false;
        $values['epp_code'] = 'SHOULD-BE-CLEARED';

        $prepared = $this->extension()
            ->prepareCheckoutConfigForCart(
                $values,
                [
                    'default_nameserver_1' => 'red.proniit.com',
                    'default_nameserver_2' => 'yellow.proniit.com',
                    'allow_custom_nameservers' => true,
                ],
            );

        $this->assertSame('example', $prepared['domain_sld']);
        $this->assertSame('.co.za', $prepared['domain_tld']);
        $this->assertSame(
            'red.proniit.com',
            $prepared['nameserver_1'],
        );
        $this->assertSame(
            'yellow.proniit.com',
            $prepared['nameserver_2'],
        );
        $this->assertSame('', $prepared['nameserver_3']);
        $this->assertSame('', $prepared['epp_code']);
    }

    public function test_it_preserves_selected_custom_nameservers_for_cart(): void
    {
        $this->fakePricing();
        $this->fakeLookup(
            isRegistered: false,
            isAvailable: true,
        );

        $values = $this->domainProperties();
        $values['use_custom_nameservers'] = true;
        $values['nameserver_1'] = ' NS1.CUSTOMER.TEST. ';
        $values['nameserver_2'] = ' NS2.CUSTOMER.TEST. ';

        $prepared = $this->extension()
            ->prepareCheckoutConfigForCart(
                $values,
                [
                    'allow_custom_nameservers' => true,
                ],
            );

        $this->assertTrue(
            $prepared['use_custom_nameservers'],
        );
        $this->assertSame(
            'NS1.CUSTOMER.TEST.',
            $prepared['nameserver_1'],
        );
        $this->assertSame(
            'NS2.CUSTOMER.TEST.',
            $prepared['nameserver_2'],
        );
    }

    public function test_it_revalidates_availability_before_cart_persistence(): void
    {
        $this->fakePricing();
        $this->fakeLookup(
            isRegistered: true,
            isAvailable: false,
        );

        $this->expectException(
            \InvalidArgumentException::class,
        );

        $this->expectExceptionMessage(
            'The selected domain is not available for registration.',
        );

        $this->extension()->prepareCheckoutConfigForCart(
            $this->domainProperties(),
        );
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

        $rule = collect($config[1]['validation'])
            ->first(
                static fn (mixed $rule): bool => $rule instanceof \Closure,
            );

        $this->assertInstanceOf(\Closure::class, $rule);

        return $rule;
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
