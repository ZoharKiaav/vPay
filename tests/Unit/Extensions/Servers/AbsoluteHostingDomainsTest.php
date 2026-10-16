<?php

namespace Tests\Unit\Extensions\Servers;

use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Paymenter\Extensions\Servers\AbsoluteHostingDomains\AbsoluteHostingDomains;
use Tests\TestCase;

class AbsoluteHostingDomainsTest extends TestCase
{
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

    private function extension(): AbsoluteHostingDomains
    {
        return new AbsoluteHostingDomains([
            'api_endpoint' => 'https://api.example.test',
            'api_username' => 'reseller@example.com',
            'api_key' => '1234567890QWERTYUIOPASDFGHJKL',
        ]);
    }
}
