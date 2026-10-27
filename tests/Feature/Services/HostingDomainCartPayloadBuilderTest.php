<?php

namespace Tests\Feature\Services;

use App\Models\Currency;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Server;
use App\Services\HostingDomainCartPayloadBuilder;
use App\Services\HostingDomainCheckoutService;
use App\Services\HostingDomainSelectionService;
use App\Services\HostingRegistrarProductResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class HostingDomainCartPayloadBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Http::fake([
            'https://api.example.test/tlds/pricing' => Http::response(
                $this->pricingFixture(),
            ),
        ]);
    }

    public function test_registration_builds_separate_linked_payloads(): void
    {
        $this->fakeLookup(
            isRegistered: false,
            isAvailable: true,
        );

        [
            $hostingProduct,
            $hostingPlan,
            $registrarProduct,
            $registrarPlan,
        ] = $this->configuredProducts();

        $payloads = $this->builder()->build(
            $hostingProduct,
            $hostingPlan,
            [],
            [
                'domain_mode' => 'register',
                'domain_sld' => ' Example ',
                'domain_tld' => '.co.za',
                'registration_period' => '1',
            ],
        );

        $this->assertTrue($payloads->hasRegistrarItem());
        $this->assertTrue(Str::isUuid($payloads->linkId));

        $this->assertSame(
            $hostingProduct->id,
            $payloads->hosting['product']->id,
        );

        $this->assertSame(
            $hostingPlan->id,
            $payloads->hosting['plan']->id,
        );

        $this->assertSame(
            $registrarProduct->id,
            $payloads->registrar['product']->id,
        );

        $this->assertSame(
            $registrarPlan->id,
            $payloads->registrar['plan']->id,
        );

        $this->assertSame(
            $payloads->linkId,
            $payloads->hosting['checkout_config'][
                'hosting_domain_link_id'
            ],
        );

        $this->assertSame(
            $payloads->linkId,
            $payloads->registrar['checkout_config'][
                'hosting_domain_link_id'
            ],
        );

        $this->assertSame(
            'hosting',
            $payloads->hosting['checkout_config'][
                'hosting_domain_link_role'
            ],
        );

        $this->assertSame(
            'registrar',
            $payloads->registrar['checkout_config'][
                'hosting_domain_link_role'
            ],
        );

        $this->assertSame(
            'example.co.za',
            $payloads->hosting['checkout_config']['domain'],
        );

        $this->assertSame(
            'register',
            $payloads->registrar['checkout_config'][
                'domain_action'
            ],
        );
    }

    public function test_transfer_preserves_the_epp_code_only_on_registrar_payload(): void
    {
        $this->fakeLookup(
            isRegistered: true,
            isAvailable: false,
        );

        [
            $hostingProduct,
            $hostingPlan,
        ] = $this->configuredProducts();

        $payloads = $this->builder()->build(
            $hostingProduct,
            $hostingPlan,
            [],
            [
                'domain_mode' => 'transfer',
                'domain_sld' => 'example',
                'domain_tld' => '.co.za',
                'registration_period' => '1',
                'epp_code' => ' TEST-EPP-CODE ',
            ],
        );

        $this->assertTrue($payloads->hasRegistrarItem());

        $this->assertSame(
            'TEST-EPP-CODE',
            $payloads->registrar['checkout_config'][
                'epp_code'
            ],
        );

        $this->assertSame(
            'TEST-EPP-CODE',
            $payloads->hosting['checkout_config'][
                'epp_code'
            ],
        );
    }

    public function test_external_mode_builds_only_the_hosting_payload(): void
    {
        $hostingProduct = $this->product(
            'Hosting',
            $this->server('Hestia'),
        );

        $hostingPlan = $this->plan(
            $hostingProduct,
            'Hosting monthly',
        );

        $payloads = $this->builder()->build(
            $hostingProduct,
            $hostingPlan,
            [],
            [
                'domain_mode' => 'external',
                'domain' => ' Existing.Example.COM. ',
            ],
        );

        $this->assertFalse($payloads->hasRegistrarItem());
        $this->assertNull($payloads->registrar);

        $this->assertSame(
            'existing.example.com',
            $payloads->hosting['checkout_config']['domain'],
        );

        $this->assertSame(
            'external',
            $payloads->hosting['checkout_config'][
                'domain_mode'
            ],
        );
    }

    public function test_customer_cannot_replace_the_configured_registrar_product(): void
    {
        $this->fakeLookup(
            isRegistered: false,
            isAvailable: true,
        );

        [
            $hostingProduct,
            $hostingPlan,
            $registrarProduct,
        ] = $this->configuredProducts();

        $payloads = $this->builder()->build(
            $hostingProduct,
            $hostingPlan,
            [],
            [
                'domain_mode' => 'register',
                'domain_sld' => 'example',
                'domain_tld' => '.co.za',
                'registration_period' => '1',
                'registrar_product_id' => 999999,
            ],
        );

        $this->assertSame(
            $registrarProduct->id,
            $payloads->registrar['product']->id,
        );

        $this->assertSame(
            $registrarProduct->id,
            $payloads->hosting['checkout_config'][
                'registrar_product_id'
            ],
        );
    }

    public function test_it_rejects_a_registrar_product_without_an_available_plan(): void
    {
        $hostingProduct = $this->product(
            'Hosting',
            $this->server('Hestia'),
        );

        $hostingPlan = $this->plan(
            $hostingProduct,
            'Hosting monthly',
        );

        $registrarProduct = $this->product(
            'Domain registration',
            $this->server('AbsoluteHostingDomains'),
        );

        $hostingProduct->settings()->create([
            'key' => HostingRegistrarProductResolver::SETTING_KEY,
            'type' => 'integer',
            'value' => $registrarProduct->id,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The configured registrar product has no available plan in the selected currency.',
        );

        $this->builder()->build(
            $hostingProduct,
            $hostingPlan,
            [],
            [
                'domain_mode' => 'register',
                'domain_sld' => 'example',
                'domain_tld' => '.co.za',
                'registration_period' => '1',
            ],
        );
    }

    public function test_existing_link_identifier_is_preserved(): void
    {
        $this->fakeLookup(
            isRegistered: false,
            isAvailable: true,
        );

        [
            $hostingProduct,
            $hostingPlan,
        ] = $this->configuredProducts();

        $linkId = (string) Str::uuid();

        $payloads = $this->builder()->build(
            $hostingProduct,
            $hostingPlan,
            [],
            [
                'domain_mode' => 'register',
                'domain_sld' => 'example',
                'domain_tld' => '.co.za',
                'registration_period' => '1',
            ],
            $linkId,
        );

        $this->assertSame($linkId, $payloads->linkId);
    }

    private function configuredProducts(): array
    {
        $hostingProduct = $this->product(
            'Hosting',
            $this->server('Hestia'),
        );

        $hostingPlan = $this->plan(
            $hostingProduct,
            'Hosting monthly',
        );

        $registrarProduct = $this->product(
            'Domain registration',
            $this->server('AbsoluteHostingDomains'),
        );

        $registrarPlan = $this->plan(
            $registrarProduct,
            'Domain service',
        );

        $hostingProduct->settings()->create([
            'key' => HostingRegistrarProductResolver::SETTING_KEY,
            'type' => 'integer',
            'value' => $registrarProduct->id,
        ]);

        return [
            $hostingProduct,
            $hostingPlan,
            $registrarProduct,
            $registrarPlan,
        ];
    }

    private function server(
        string $extension,
    ): Server {
        $server = Server::query()->create([
            'name' => $extension . ' test server',
            'extension' => $extension,
            'type' => 'server',
            'enabled' => true,
        ]);

        if ($extension === 'AbsoluteHostingDomains') {
            foreach ([
                'api_endpoint' => 'https://api.example.test',
                'api_username' => 'reseller@example.com',
                'api_key' => '1234567890QWERTYUIOPASDFGHJKL',
            ] as $key => $value) {
                $server->settings()->create([
                    'key' => $key,
                    'value' => $value,
                ]);
            }
        }

        return $server;
    }

    private function product(
        string $name,
        Server $server,
    ): Product {
        return Product::factory()->create([
            'name' => $name,
            'server_id' => $server->id,
        ]);
    }

    private function plan(
        Product $product,
        string $name,
    ): Plan {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'ZAR'],
            [
                'name' => 'South African Rand',
                'prefix' => 'R',
                'suffix' => null,
                'exchange_rate' => 1,
                'default' => true,
            ],
        );

        session(['currency' => $currency->code]);

        $plan = $product->plans()->create([
            'name' => $name,
            'type' => 'recurring',
            'billing_period' => 1,
            'billing_unit' => 'year',
            'sort' => 1,
        ]);

        Price::query()->create([
            'plan_id' => $plan->id,
            'currency_code' => $currency->code,
            'price' => 100,
            'setup_fee' => 0,
        ]);

        return $plan->fresh('prices.currency');
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

    private function builder(): HostingDomainCartPayloadBuilder
    {
        return new HostingDomainCartPayloadBuilder(
            new HostingDomainCheckoutService(
                new HostingRegistrarProductResolver,
                new HostingDomainSelectionService,
            ),
            new HostingRegistrarProductResolver,
        );
    }
}
