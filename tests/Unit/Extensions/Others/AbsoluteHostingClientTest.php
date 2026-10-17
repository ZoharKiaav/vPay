<?php

namespace Tests\Unit\Extensions\Others;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingClient;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingException;
use Tests\TestCase;

class AbsoluteHostingClientTest extends TestCase
{
    private const ENDPOINT = 'https://absolute-hosting.test/api/index.php';

    private const USERNAME = 'reseller@example.com';

    private const API_KEY = '1234567890QWERTYUIOPASDFGHJKL';

    public function test_it_generates_the_documented_token(): void
    {
        $timestamp = 1792054800;
        $expected = 'NTYzZDFkNDQxOTNkODU5MjJmODU5Y2Q1MDJjM2U0YTY1NzQ0MjM1MzhhYWI5YTkxMzQyODAzZjEyMGUxNDc4Mg==';

        $client = $this->client();

        $this->assertSame($expected, $client->token($timestamp));
        $this->assertSame(88, strlen($client->token($timestamp)));
    }

    public function test_it_sends_required_authentication_headers(): void
    {
        Http::fake([
            self::ENDPOINT . '/version' => Http::response([
                'version' => '1.0.0',
            ]),
        ]);

        $result = $this->client()->version();

        $this->assertSame(['version' => '1.0.0'], $result);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === self::ENDPOINT . '/version'
                && $request->hasHeader('username', self::USERNAME)
                && $request->hasHeader('token')
                && $request->hasHeader('timestamp')
                && $request->hasHeader(
                    'User-Agent',
                    'vPay Absolute Hosting Domains',
                );
        });
    }

    public function test_it_fetches_tld_pricing(): void
    {
        Http::fake([
            self::ENDPOINT . '/tlds/pricing' => Http::response([
                [
                    'tld' => '.co.za',
                    'registrationPrice' => '93.00',
                    'renewalPrice' => '93.00',
                    'transferPrice' => '0.00',
                    'currencyCode' => 'ZAR',
                ],
            ]),
        ]);

        $pricing = $this->client()->tldPricing();

        $this->assertSame('.co.za', $pricing[0]['tld']);
        $this->assertSame('93.00', $pricing[0]['registrationPrice']);
        $this->assertSame('ZAR', $pricing[0]['currencyCode']);
    }

    public function test_it_posts_domain_lookup_as_form_data(): void
    {
        Http::fake([
            self::ENDPOINT . '/domains/lookup' => Http::response([
                'available' => true,
            ]),
        ]);

        $result = $this->client()->lookup(
            searchTerm: 'example',
            tldsToInclude: ['.co.za'],
        );

        $this->assertTrue($result['available']);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request->url() === self::ENDPOINT . '/domains/lookup'
                && $data['searchTerm'] === 'example'
                && $data['punyCodeSearchTerm'] === 'example'
                && $data['tldsToInclude'] === ['.co.za']
                && $data['isIdnDomain'] === false
                && $data['premiumEnabled'] === false;
        });
    }

    public function test_it_normalises_api_errors(): void
    {
        Http::fake([
            self::ENDPOINT . '/billing/credits' => Http::response([
                'error' => 'Provided username is invalid',
            ], 401),
        ]);

        try {
            $this->client()->credits();
            $this->fail('Expected AbsoluteHostingException was not thrown.');
        } catch (AbsoluteHostingException $exception) {
            $this->assertSame(
                'Provided username is invalid',
                $exception->getMessage(),
            );
            $this->assertSame(401, $exception->statusCode);
            $this->assertSame(
                ['error' => 'Provided username is invalid'],
                $exception->responseData,
            );
        }
    }

    public function test_it_rejects_invalid_json(): void
    {
        Http::fake([
            self::ENDPOINT . '/tlds' => Http::response(
                '<html>Unexpected response</html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $this->expectException(AbsoluteHostingException::class);
        $this->expectExceptionMessage(
            'Absolute Hosting API returned invalid JSON.',
        );

        $this->client()->tlds();
    }

    public function test_it_posts_the_documented_registration_contract(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            self::ENDPOINT . '/order/domains/register' => Http::response([
                'success' => true,
                'domain' => 'example.co.za',
            ]),
        ]);

        $payload = $this->registrationPayload();

        $result = $this->client()->registerDomain($payload);

        $this->assertTrue($result['success']);
        $this->assertSame('example.co.za', $result['domain']);

        Http::assertSent(function (Request $request) use ($payload): bool {
            return $request->method() === 'POST'
                && $request->url()
                    === self::ENDPOINT . '/order/domains/register'
                && $request->data() === $payload;
        });

        Http::assertSentCount(1);
    }

    public function test_it_posts_the_documented_transfer_contract(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            self::ENDPOINT . '/order/domains/transfer' => Http::response([
                'success' => true,
                'domain' => 'example.co.za',
            ]),
        ]);

        $payload = $this->transferPayload();

        $result = $this->client()->transferDomain($payload);

        $this->assertTrue($result['success']);
        $this->assertSame('example.co.za', $result['domain']);

        Http::assertSent(function (Request $request) use ($payload): bool {
            return $request->method() === 'POST'
                && $request->url()
                    === self::ENDPOINT . '/order/domains/transfer'
                && $request->data() === $payload;
        });

        Http::assertSentCount(1);
    }

    public function test_registration_and_transfer_use_distinct_endpoints(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            self::ENDPOINT . '/order/domains/register' => Http::response([
                'operation' => 'register',
            ]),
            self::ENDPOINT . '/order/domains/transfer' => Http::response([
                'operation' => 'transfer',
            ]),
        ]);

        $registration = $this->client()->registerDomain(
            $this->registrationPayload(),
        );

        $transfer = $this->client()->transferDomain(
            $this->transferPayload(),
        );

        $this->assertSame('register', $registration['operation']);
        $this->assertSame('transfer', $transfer['operation']);
        Http::assertSentCount(2);
    }

    private function registrationPayload(): array
    {
        return [
            'domain' => 'example.co.za',
            'regperiod' => 1,
            'idnLanguage' => '',
            'domainfields' => base64_encode(serialize([])),
            'addons' => [
                'dnsmanagement' => 0,
                'emailforwarding' => 0,
                'idprotection' => 0,
            ],
            'nameservers' => [
                'ns1' => 'ns1.example.test',
                'ns2' => 'ns2.example.test',
                'ns3' => '',
                'ns4' => '',
                'ns5' => '',
            ],
            'contacts' => [
                'registrant' => $this->registrantContact(),
            ],
        ];
    }

    private function transferPayload(): array
    {
        return [
            'domain' => 'example.co.za',
            'regperiod' => 1,
            'idnLanguage' => '',
            'eppcode' => 'TEST-EPP-CODE',
            'domainfields' => base64_encode(serialize([])),
            'addons' => [
                'dnsmanagement' => 0,
                'emailforwarding' => 0,
                'idprotection' => 0,
            ],
            'nameservers' => [
                'ns1.example.test',
                'ns2.example.test',
                '',
                '',
                '',
            ],
            'contacts' => [
                'registrant' => $this->registrantContact(),
            ],
        ];
    }

    private function registrantContact(): array
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

    private function client(): AbsoluteHostingClient
    {
        return new AbsoluteHostingClient(
            endpoint: self::ENDPOINT,
            username: self::USERNAME,
            apiKey: self::API_KEY,
        );
    }
}
