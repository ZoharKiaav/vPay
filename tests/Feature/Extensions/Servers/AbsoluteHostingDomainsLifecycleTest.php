<?php

namespace Tests\Feature\Extensions\Servers;

use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Models\ManagedDomain;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainOrderService;
use Paymenter\Extensions\Servers\AbsoluteHostingDomains\AbsoluteHostingDomains;
use stdClass;
use Tests\TestCase;

class AbsoluteHostingDomainsLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://api.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'app.cipher' => 'AES-256-CBC',
        ]);

        if (!Schema::hasTable('ext_absolute_hosting_domains')) {
            $migration = require base_path(
                'extensions/Others/AbsoluteHostingDomains/'
                . 'database/migrations/'
                . '2026_10_16_000001_create_ext_absolute_hosting_domains_table.php'
            );

            $migration->up();
        }

        Http::preventStrayRequests();
    }

    public function test_create_server_submits_a_registration_order(): void
    {
        Http::fake([
            self::ENDPOINT . '/order/domains/register' => Http::response([
                'success' => true,
                'domain' => 'example.co.za',
                'order_reference' => 'REGISTER-001',
            ]),
        ]);

        $service = $this->service();

        $response = $this->extension()->createServer(
            $service,
            [],
            $this->domainProperties(),
        );

        $managedDomain = ManagedDomain::query()
            ->where('service_id', $service->id)
            ->firstOrFail();

        $this->assertTrue($response['success']);
        $this->assertSame(
            'REGISTER-001',
            $response['order_reference'],
        );
        $this->assertSame('example.co.za', $managedDomain->domain_name);
        $this->assertSame('.co.za', $managedDomain->tld);
        $this->assertSame(1, $managedDomain->registration_period);
        $this->assertSame(
            ManagedDomain::STATUS_PENDING,
            $managedDomain->status,
        );
        $this->assertSame(
            DomainOrderService::PROVIDER_STATUS_SUBMITTED,
            $managedDomain->provider_status,
        );
        $this->assertSame(
            'register',
            $managedDomain->provider_data['operation'],
        );
        $this->assertSame(
            ['ns1.example.test', 'ns2.example.test'],
            $managedDomain->nameservers,
        );
        $this->assertSame(
            'registrant@example.test',
            $managedDomain->contact_data['registrant']['email'],
        );

        $this->assertDatabaseHas('ext_absolute_hosting_domains', [
            'service_id' => $service->id,
            'domain_name' => 'example.co.za',
            'provider_status' => 'submitted',
        ]);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url()
                    === self::ENDPOINT . '/order/domains/register'
                && $request->data()['domain'] === 'example.co.za'
                && $request->data()['regperiod'] === 1
                && $request->data()['nameservers']['ns1']
                    === 'ns1.example.test'
                && $request->data()['nameservers']['ns2']
                    === 'ns2.example.test';
        });

        Http::assertSentCount(1);
    }

    public function test_create_server_routes_a_transfer_order(): void
    {
        Http::fake([
            self::ENDPOINT . '/order/domains/transfer' => Http::response([
                'success' => true,
                'domain' => 'example.co.za',
                'order_reference' => 'TRANSFER-001',
            ]),
        ]);

        $service = $this->service();
        $properties = $this->domainProperties();
        $properties['domain_action'] = 'transfer';
        $properties['epp_code'] = 'TEST-EPP-CODE';

        $response = $this->extension()->createServer(
            $service,
            [],
            $properties,
        );

        $managedDomain = ManagedDomain::query()
            ->where('service_id', $service->id)
            ->firstOrFail();

        $this->assertTrue($response['success']);
        $this->assertSame(
            'TRANSFER-001',
            $response['order_reference'],
        );
        $this->assertSame(
            ManagedDomain::STATUS_TRANSFER_PENDING,
            $managedDomain->status,
        );
        $this->assertSame(
            DomainOrderService::PROVIDER_STATUS_SUBMITTED,
            $managedDomain->provider_status,
        );
        $this->assertSame(
            'transfer',
            $managedDomain->provider_data['operation'],
        );

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url()
                    === self::ENDPOINT . '/order/domains/transfer'
                && $request->data()['domain'] === 'example.co.za'
                && $request->data()['eppcode'] === 'TEST-EPP-CODE'
                && $request->data()['nameservers'][0]
                    === 'ns1.example.test'
                && $request->data()['nameservers'][1]
                    === 'ns2.example.test';
        });

        Http::assertSentCount(1);
    }

    public function test_create_server_blocks_a_duplicate_provider_attempt(): void
    {
        Http::fake([
            self::ENDPOINT . '/order/domains/register' => Http::response([
                'success' => true,
                'domain' => 'example.co.za',
                'order_reference' => 'REGISTER-001',
            ]),
        ]);

        $service = $this->service();
        $extension = $this->extension();

        $extension->createServer(
            $service,
            [],
            $this->domainProperties(),
        );

        try {
            $extension->createServer(
                $service,
                [],
                $this->domainProperties(),
            );

            $this->fail(
                'Expected duplicate lifecycle submission protection.',
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'A provider order has already been attempted for this service.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            1,
            ManagedDomain::query()
                ->where('service_id', $service->id)
                ->count(),
        );

        $managedDomain = ManagedDomain::query()
            ->where('service_id', $service->id)
            ->firstOrFail();

        $this->assertSame(
            DomainOrderService::PROVIDER_STATUS_SUBMITTED,
            $managedDomain->provider_status,
        );

        Http::assertSentCount(1);
    }

    public function test_create_server_persists_uncertain_provider_failure(): void
    {
        Http::fake([
            self::ENDPOINT . '/order/domains/register' => Http::response([
                'message' => 'Simulated lifecycle provider timeout.',
            ], 503),
        ]);

        $service = $this->service();

        try {
            $this->extension()->createServer(
                $service,
                [],
                $this->domainProperties(),
            );

            $this->fail(
                'Expected the lifecycle provider request to fail.',
            );
        } catch (AbsoluteHostingException $exception) {
            $this->assertSame(
                'Simulated lifecycle provider timeout.',
                $exception->getMessage(),
            );
        }

        $managedDomain = ManagedDomain::query()
            ->where('service_id', $service->id)
            ->firstOrFail();

        $this->assertSame(
            DomainOrderService::PROVIDER_STATUS_UNCERTAIN,
            $managedDomain->provider_status,
        );
        $this->assertSame(
            'uncertain',
            $managedDomain->provider_data['submission_state'],
        );
        $this->assertSame(
            'register',
            $managedDomain->provider_data['operation'],
        );
        $this->assertSame(
            'Simulated lifecycle provider timeout.',
            $managedDomain->provider_data['message'],
        );

        try {
            $this->extension()->createServer(
                $service,
                [],
                $this->domainProperties(),
            );

            $this->fail(
                'Expected uncertain lifecycle retry protection.',
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'A provider order has already been attempted for this service.',
                $exception->getMessage(),
            );
        }

        Http::assertSentCount(1);
    }

    private function service(): Service
    {
        $customer = User::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'Registrant',
            'email' => 'registrant@example.test',
        ]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'status' => Service::STATUS_PENDING,
        ]);

        $customer->setRelation(
            'properties',
            new Collection([
                $this->property('phone', '+27.100000000'),
                $this->property('company_name', 'Example Test'),
                $this->property('country', 'ZA'),
                $this->property('address', '1 Example Road'),
                $this->property('address2', ''),
                $this->property('city', 'Johannesburg'),
                $this->property('state', 'Gauteng'),
                $this->property('zip', '2000'),
                $this->property('tax_id', ''),
            ]),
        );

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
            'api_endpoint' => self::ENDPOINT,
            'api_username' => 'reseller@example.test',
            'api_key' => '1234567890QWERTYUIOPASDFGHJKL',
        ]);
    }
}
