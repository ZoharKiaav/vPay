<?php

namespace Tests\Feature\Extensions\Others;

use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingClient;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Models\ManagedDomain;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainOrderPayloadFactory;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainOrderService;
use Tests\TestCase;

class DomainOrderPersistenceTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_it_persists_a_successful_submission(): void
    {
        $managedDomain = $this->managedDomain();
        $client = $this->client([
            'success' => true,
            'order_reference' => 'TEST-ORDER-001',
        ]);

        $response = $this->orderService($client)->submit(
            $managedDomain,
            'register',
            $this->serviceProperties(),
            $this->customer(),
            $this->customerProperties(),
        );

        $this->assertSame(
            'TEST-ORDER-001',
            $response['order_reference'],
        );

        $persisted = $managedDomain->fresh();

        $this->assertNotNull($persisted);
        $this->assertSame(
            DomainOrderService::PROVIDER_STATUS_SUBMITTED,
            $persisted->provider_status,
        );
        $this->assertSame('example.co.za', $persisted->domain_name);
        $this->assertSame('.co.za', $persisted->tld);
        $this->assertSame(1, $persisted->registration_period);
        $this->assertSame(
            ['ns1.example.test', 'ns2.example.test'],
            $persisted->nameservers,
        );
        $this->assertSame(
            'registrant@example.test',
            $persisted->contact_data['registrant']['email'],
        );
        $this->assertSame(
            DomainOrderService::PROVIDER_STATUS_SUBMITTED,
            $persisted->provider_data['submission_state'],
        );
        $this->assertSame(1, $client->registrationCalls);
        $this->assertSame(0, $client->transferCalls);
        $this->assertSame(
            DomainOrderService::PROVIDER_STATUS_SUBMITTING,
            $client->providerStatusObservedBeforeRequest,
        );

        $this->assertDatabaseHas('ext_absolute_hosting_domains', [
            'id' => $persisted->id,
            'service_id' => $persisted->service_id,
            'domain_name' => 'example.co.za',
            'provider_status' => DomainOrderService::PROVIDER_STATUS_SUBMITTED,
        ]);

        $rawContactData = DB::table('ext_absolute_hosting_domains')
            ->where('id', $persisted->id)
            ->value('contact_data');

        $this->assertIsString($rawContactData);
        $this->assertStringNotContainsString(
            'registrant@example.test',
            $rawContactData,
        );
    }

    public function test_it_persists_uncertainty_after_provider_failure(): void
    {
        $managedDomain = $this->managedDomain();
        $client = $this->client(
            exception: new AbsoluteHostingException(
                'Simulated provider timeout.',
            ),
        );

        try {
            $this->orderService($client)->submit(
                $managedDomain,
                'register',
                $this->serviceProperties(),
                $this->customer(),
                $this->customerProperties(),
            );

            $this->fail('Expected simulated provider failure.');
        } catch (AbsoluteHostingException $exception) {
            $this->assertSame(
                'Simulated provider timeout.',
                $exception->getMessage(),
            );
        }

        $persisted = $managedDomain->fresh();

        $this->assertNotNull($persisted);
        $this->assertSame(
            DomainOrderService::PROVIDER_STATUS_UNCERTAIN,
            $persisted->provider_status,
        );
        $this->assertSame(
            DomainOrderService::PROVIDER_STATUS_UNCERTAIN,
            $persisted->provider_data['submission_state'],
        );
        $this->assertSame(
            'Simulated provider timeout.',
            $persisted->provider_data['message'],
        );
        $this->assertSame(1, $client->registrationCalls);

        try {
            $this->orderService($client)->submit(
                $managedDomain,
                'register',
                $this->serviceProperties(),
                $this->customer(),
                $this->customerProperties(),
            );

            $this->fail('Expected duplicate provider attempt protection.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'A provider order has already been attempted for this service.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(1, $client->registrationCalls);

        $this->assertDatabaseHas('ext_absolute_hosting_domains', [
            'id' => $persisted->id,
            'provider_status' => DomainOrderService::PROVIDER_STATUS_UNCERTAIN,
        ]);
    }

    public function test_database_blocks_two_domains_for_one_service(): void
    {
        $managedDomain = $this->managedDomain();

        $this->expectException(QueryException::class);

        ManagedDomain::query()->create([
            'service_id' => $managedDomain->service_id,
            'domain_name' => 'second-example.co.za',
            'tld' => '.co.za',
            'status' => ManagedDomain::STATUS_PENDING,
            'registration_period' => 1,
        ]);
    }

    public function test_deleting_service_cascades_managed_domain(): void
    {
        $managedDomain = $this->managedDomain();
        $managedDomainId = $managedDomain->id;
        $service = $managedDomain->service;

        $this->assertDatabaseHas('ext_absolute_hosting_domains', [
            'id' => $managedDomainId,
            'service_id' => $service->id,
        ]);

        $service->delete();

        $this->assertDatabaseMissing('ext_absolute_hosting_domains', [
            'id' => $managedDomainId,
        ]);
    }

    private function managedDomain(): ManagedDomain
    {
        $user = User::factory()->create();

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'status' => Service::STATUS_PENDING,
        ]);

        return ManagedDomain::query()->create([
            'service_id' => $service->id,
            'domain_name' => 'pending-example.co.za',
            'tld' => '.co.za',
            'status' => ManagedDomain::STATUS_PENDING,
            'registration_period' => 1,
        ]);
    }

    private function client(
        array $response = ['success' => true],
        ?AbsoluteHostingException $exception = null,
    ): AbsoluteHostingClient {
        return new class($response, $exception) extends AbsoluteHostingClient
        {
            public int $registrationCalls = 0;

            public int $transferCalls = 0;

            public ?string $providerStatusObservedBeforeRequest = null;

            public function __construct(
                private readonly array $response,
                private readonly ?AbsoluteHostingException $exception,
            ) {
                parent::__construct(
                    endpoint: 'https://absolute-hosting.test/api/index.php',
                    username: 'reseller@example.com',
                    apiKey: '1234567890QWERTYUIOPASDFGHJKL',
                );
            }

            public function registerDomain(array $payload): mixed
            {
                $this->registrationCalls++;

                $this->providerStatusObservedBeforeRequest =
                    ManagedDomain::query()
                        ->firstOrFail()
                        ->provider_status;

                if ($this->exception !== null) {
                    throw $this->exception;
                }

                return $this->response;
            }

            public function transferDomain(array $payload): mixed
            {
                $this->transferCalls++;

                if ($this->exception !== null) {
                    throw $this->exception;
                }

                return $this->response;
            }
        };
    }

    private function orderService(
        AbsoluteHostingClient $client,
    ): DomainOrderService {
        return new DomainOrderService(
            $client,
            new DomainOrderPayloadFactory,
        );
    }

    private function serviceProperties(): array
    {
        return [
            'domain_sld' => 'example',
            'domain_tld' => '.co.za',
            'registration_period' => '1',
            'nameserver_1' => 'ns1.example.test',
            'nameserver_2' => 'ns2.example.test',
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
}
