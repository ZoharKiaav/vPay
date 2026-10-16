<?php

namespace Tests\Unit\Extensions\Others;

use Illuminate\Support\Facades\Http;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingClient;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainCatalogService;
use Tests\TestCase;

class DomainCatalogServiceTest extends TestCase
{
    public function test_it_normalises_provider_pricing(): void
    {
        Http::fake([
            'https://api.example.test/tlds/pricing' => Http::response([
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
                    'years' => [1, 2, 3, 4, 5],
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
            ]),
        ]);

        $pricing = $this->service()->pricing();

        $this->assertSame('93.00', $pricing['.co.za']['registration_price']);
        $this->assertSame('0.00', $pricing['.co.za']['transfer_price']);
        $this->assertSame([1, 2, 3, 4, 5], $pricing['.co.za']['years']);
        $this->assertTrue($pricing['.co.za']['registration_available']);

        $this->assertNull($pricing['.ai']['registration_price']);
        $this->assertNull($pricing['.ai']['renewal_price']);
        $this->assertFalse($pricing['.ai']['registration_available']);
    }

    public function test_it_normalises_availability_results(): void
    {
        Http::fake([
            'https://api.example.test/domains/lookup' => Http::response([
                [
                    'domainName' => 'vkloud-contract-probe-20261016.co.za',
                    'idnDomainName' => 'vkloud-contract-probe-20261016.co.za',
                    'tld' => 'co.za',
                    'tldNoDots' => 'coza',
                    'sld' => 'vkloud-contract-probe-20261016',
                    'idnSld' => 'vkloud-contract-probe-20261016',
                    'status' => 'available for registration',
                    'legacyStatus' => 'available',
                    'score' => 1,
                    'isRegistered' => false,
                    'isAvailable' => true,
                    'isValidDomain' => true,
                    'domainErrorMessage' => '',
                    'pricing' => [],
                    'shortestPeriod' => [],
                    'group' => 'sale',
                    'minLength' => 0,
                    'maxLength' => 0,
                    'isPremium' => false,
                    'premiumCostPricing' => [],
                ],
            ]),
        ]);

        $results = $this->service()->availability(
            'VKLOUD-CONTRACT-PROBE-20261016.',
            ['CO.ZA'],
        );

        $this->assertCount(1, $results);
        $this->assertSame(
            'vkloud-contract-probe-20261016.co.za',
            $results[0]['domain_name'],
        );
        $this->assertSame('.co.za', $results[0]['tld']);
        $this->assertTrue($results[0]['is_available']);
        $this->assertFalse($results[0]['is_registered']);
        $this->assertTrue($results[0]['is_valid']);
        $this->assertFalse($results[0]['is_premium']);
        $this->assertNull($results[0]['error_message']);

        Http::assertSent(function ($request): bool {
            return $request->url()
                === 'https://api.example.test/domains/lookup'
                && $request['searchTerm']
                    === 'vkloud-contract-probe-20261016'
                && $request['tldsToInclude'] === ['.co.za']
                && $request['premiumEnabled'] === false;
        });
    }

    public function test_it_normalises_and_sorts_tlds(): void
    {
        Http::fake([
            'https://api.example.test/tlds' => Http::response([
                '.co.za',
                'COM',
                '.co.za',
            ]),
        ]);

        $this->assertSame(
            ['.co.za', '.com'],
            $this->service()->tlds(),
        );
    }

    public function test_it_finds_pricing_by_normalised_tld(): void
    {
        Http::fake([
            'https://api.example.test/tlds/pricing' => Http::response([
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
                    'years' => [1, 2, 3, 4, 5],
                ],
            ]),
        ]);

        $pricing = $this->service()->pricingFor('CO.ZA');

        $this->assertNotNull($pricing);
        $this->assertSame('.co.za', $pricing['tld']);
        $this->assertSame('ZAR', $pricing['currency_code']);
    }

    public function test_it_rejects_invalid_pricing_contracts(): void
    {
        Http::fake([
            'https://api.example.test/tlds/pricing' => Http::response([
                ['tld' => '.co.za'],
            ]),
        ]);

        $this->expectException(AbsoluteHostingException::class);
        $this->expectExceptionMessage(
            'Absolute Hosting pricing is missing registrationPrice.',
        );

        $this->service()->pricing();
    }

    private function service(): DomainCatalogService
    {
        return new DomainCatalogService(
            new AbsoluteHostingClient(
                endpoint: 'https://api.example.test',
                username: 'reseller@example.com',
                apiKey: '1234567890QWERTYUIOPASDFGHJKL',
            ),
        );
    }
}
