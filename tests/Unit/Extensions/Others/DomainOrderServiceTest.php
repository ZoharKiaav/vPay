<?php

namespace Tests\Unit\Extensions\Others;

use LogicException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingClient;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Models\ManagedDomain;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainOrderPayloadFactory;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainOrderService;
use Tests\TestCase;

class DomainOrderServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'app.cipher' => 'AES-256-CBC',
        ]);
    }

    public function test_it_persists_a_guard_before_registration(): void
    {
        $domain = $this->managedDomain();
        $client = $this->client();

        $response = $this->service($client)->submit(
            $domain,
            'register',
            $this->serviceProperties(),
            $this->customer(),
            $this->customerProperties(),
        );

        $this->assertSame(['success' => true], $response);
        $this->assertSame(1, $client->registrationCalls);
        $this->assertSame(0, $client->transferCalls);
        $this->assertCount(2, $domain->savedStates);
        $this->assertSame(
            DomainOrderService::PROVIDER_STATUS_SUBMITTING,
            $domain->savedStates[0]['provider_status'],
        );
        $this->assertSame(
            DomainOrderService::PROVIDER_STATUS_SUBMITTED,
            $domain->savedStates[1]['provider_status'],
        );
        $this->assertSame('example.co.za', $domain->domain_name);
        $this->assertSame('.co.za', $domain->tld);
        $this->assertSame(
            ['ns1.example.test', 'ns2.example.test'],
            $domain->nameservers,
        );
    }

    public function test_it_routes_transfers_separately(): void
    {
        $domain = $this->managedDomain();
        $client = $this->client();

        $properties = $this->serviceProperties();
        $properties['epp_code'] = 'TEST-EPP-CODE';

        $this->service($client)->submit(
            $domain,
            'transfer',
            $properties,
            $this->customer(),
            $this->customerProperties(),
        );

        $this->assertSame(0, $client->registrationCalls);
        $this->assertSame(1, $client->transferCalls);
        $this->assertSame(
            ManagedDomain::STATUS_TRANSFER_PENDING,
            $domain->status,
        );
    }

    public function test_it_blocks_a_duplicate_provider_attempt(): void
    {
        $domain = $this->managedDomain();
        $domain->provider_status =
            DomainOrderService::PROVIDER_STATUS_SUBMITTED;

        $client = $this->client();

        try {
            $this->service($client)->submit(
                $domain,
                'register',
                $this->serviceProperties(),
                $this->customer(),
                $this->customerProperties(),
            );

            $this->fail('Expected duplicate-order guard failure.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'A provider order has already been attempted for this service.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, $client->registrationCalls);
        $this->assertSame(0, $client->transferCalls);
        $this->assertCount(0, $domain->savedStates);
    }

    public function test_it_blocks_resubmission_while_an_order_is_submitting(): void
    {
        $domain = $this->managedDomain();
        $domain->provider_status =
            DomainOrderService::PROVIDER_STATUS_SUBMITTING;

        $client = $this->client();

        try {
            $this->service($client)->submit(
                $domain,
                'register',
                $this->serviceProperties(),
                $this->customer(),
                $this->customerProperties(),
            );

            $this->fail(
                'Expected submitting-order guard failure.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'A provider order has already been attempted for this service.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, $client->registrationCalls);
        $this->assertSame(0, $client->transferCalls);
        $this->assertCount(0, $domain->savedStates);
    }

    public function test_it_marks_a_failed_request_as_uncertain(): void
    {
        $domain = $this->managedDomain();
        $client = $this->client(
            exception: new AbsoluteHostingException(
                'Simulated connection failure.',
            ),
        );

        try {
            $this->service($client)->submit(
                $domain,
                'register',
                $this->serviceProperties(),
                $this->customer(),
                $this->customerProperties(),
            );

            $this->fail('Expected simulated provider failure.');
        } catch (AbsoluteHostingException $exception) {
            $this->assertSame(
                'Simulated connection failure.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(1, $client->registrationCalls);
        $this->assertCount(2, $domain->savedStates);
        $this->assertSame(
            DomainOrderService::PROVIDER_STATUS_SUBMITTING,
            $domain->savedStates[0]['provider_status'],
        );
        $this->assertSame(
            DomainOrderService::PROVIDER_STATUS_UNCERTAIN,
            $domain->savedStates[1]['provider_status'],
        );
    }

    public function test_it_blocks_resubmission_after_uncertainty(): void
    {
        $domain = $this->managedDomain();
        $domain->provider_status =
            DomainOrderService::PROVIDER_STATUS_UNCERTAIN;

        $client = $this->client();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'A provider order has already been attempted for this service.',
        );

        $this->service($client)->submit(
            $domain,
            'register',
            $this->serviceProperties(),
            $this->customer(),
            $this->customerProperties(),
        );
    }

    private function managedDomain(): ManagedDomain
    {
        return new class extends ManagedDomain
        {
            public array $savedStates = [];

            public function save(array $options = []): bool
            {
                $this->savedStates[] = [
                    'domain_name' => $this->domain_name,
                    'tld' => $this->tld,
                    'status' => $this->status,
                    'provider_status' => $this->provider_status,
                    'registration_period' => $this->registration_period,
                    'nameservers' => $this->nameservers,
                    'contact_data' => $this->contact_data,
                    'provider_data' => $this->provider_data,
                ];

                return true;
            }
        };
    }

    private function client(
        ?AbsoluteHostingException $exception = null,
    ): AbsoluteHostingClient {
        return new class($exception) extends AbsoluteHostingClient
        {
            public int $registrationCalls = 0;

            public int $transferCalls = 0;

            public function __construct(
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

                if ($this->exception !== null) {
                    throw $this->exception;
                }

                return ['success' => true];
            }

            public function transferDomain(array $payload): mixed
            {
                $this->transferCalls++;

                if ($this->exception !== null) {
                    throw $this->exception;
                }

                return ['success' => true];
            }
        };
    }

    private function service(
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
