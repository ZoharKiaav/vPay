<?php

namespace Tests\Feature\Extensions\Others;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingClient;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Models\ManagedDomain;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainReconciliationService;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\ManagedDomainReconciliationService;
use Tests\TestCase;

class ManagedDomainReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT =
        'https://absolute-hosting.test/api/index.php';

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

    public function test_it_reconciles_safe_operational_fields(): void
    {
        $managedDomain = $this->managedDomain();

        Http::fake([
            self::ENDPOINT
                . '/domains/example.co.za/information' => Http::response($this->providerResponse()),
        ]);

        $result = $this->service()->reconcile(
            $managedDomain,
        );

        $persisted = $managedDomain->fresh();

        $this->assertNotNull($persisted);
        $this->assertSame('example.co.za', $result->domain);
        $this->assertSame(
            ManagedDomain::STATUS_ACTIVE,
            $persisted->status,
        );
        $this->assertSame(
            '2027-10-22',
            $persisted->expires_at?->toDateString(),
        );
        $this->assertSame([
            'ns1.example.test',
            'ns2.example.test',
        ], $persisted->nameservers);
        $this->assertTrue($persisted->transfer_locked);
        $this->assertNotNull($persisted->last_synced_at);

        $this->assertSame(
            'register',
            $persisted->provider_data['operation'],
        );
        $this->assertSame(
            'submitted',
            $persisted->provider_data['submission_state'],
        );
        $this->assertSame(
            'ORDER-001',
            $persisted->provider_data['response']['order_reference'],
        );
        $this->assertSame(
            'Active',
            $persisted->provider_data['reconciliation']['status'],
        );
        $this->assertSame(
            '2027-10-22',
            $persisted->provider_data['reconciliation']['expiry_date'],
        );

        $this->assertSame(
            'registrant@example.test',
            $persisted->contact_data['registrant']['email'],
        );
        $this->assertArrayNotHasKey(
            'registrantEmailAddress',
            $persisted->provider_data['reconciliation'],
        );
        $this->assertArrayNotHasKey(
            'unknownProviderField',
            $persisted->provider_data['reconciliation'],
        );

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->url()
                    === self::ENDPOINT
                        . '/domains/example.co.za/information';
        });

        Http::assertSentCount(1);
    }

    public function test_it_rejects_a_provider_domain_mismatch(): void
    {
        $managedDomain = $this->managedDomain();
        $before = $managedDomain->getAttributes();

        $response = $this->providerResponse();
        $response['domain'] = 'other-example.co.za';

        Http::fake([
            self::ENDPOINT
                . '/domains/example.co.za/information' => Http::response($response),
        ]);

        try {
            $this->service()->reconcile($managedDomain);

            $this->fail(
                'Expected provider domain mismatch failure.',
            );
        } catch (AbsoluteHostingException $exception) {
            $this->assertSame(
                'Absolute Hosting returned information for a different domain.',
                $exception->getMessage(),
            );
        }

        $persisted = $managedDomain->fresh();

        $this->assertNotNull($persisted);
        $this->assertSame(
            $before['status'],
            $persisted->getRawOriginal('status'),
        );
        $this->assertNull($persisted->expires_at);
        $this->assertNull($persisted->last_synced_at);
        $this->assertSame(
            'submitted',
            $persisted->provider_status,
        );
        $this->assertArrayNotHasKey(
            'reconciliation',
            $persisted->provider_data,
        );

        Http::assertSentCount(1);
    }

    public function test_unknown_provider_status_preserves_local_status(): void
    {
        $managedDomain = $this->managedDomain();

        $response = $this->providerResponse();
        $response['status'] = 'Provider-specific state';

        Http::fake([
            self::ENDPOINT
                . '/domains/example.co.za/information' => Http::response($response),
        ]);

        $this->service()->reconcile($managedDomain);

        $persisted = $managedDomain->fresh();

        $this->assertNotNull($persisted);
        $this->assertSame(
            ManagedDomain::STATUS_PENDING,
            $persisted->status,
        );
        $this->assertSame(
            'Provider-specific state',
            $persisted->provider_data['reconciliation']['status'],
        );
        $this->assertNotNull($persisted->last_synced_at);
    }

    private function managedDomain(): ManagedDomain
    {
        $user = User::factory()->create();

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'status' => Service::STATUS_ACTIVE,
        ]);

        return ManagedDomain::query()->create([
            'service_id' => $service->id,
            'domain_name' => 'example.co.za',
            'tld' => '.co.za',
            'status' => ManagedDomain::STATUS_PENDING,
            'provider_status' => 'submitted',
            'registration_period' => 1,
            'transfer_locked' => false,
            'nameservers' => [
                'old-ns1.example.test',
                'old-ns2.example.test',
            ],
            'contact_data' => [
                'registrant' => [
                    'email' => 'registrant@example.test',
                ],
            ],
            'provider_data' => [
                'operation' => 'register',
                'submission_state' => 'submitted',
                'response' => [
                    'order_reference' => 'ORDER-001',
                ],
            ],
        ]);
    }

    private function providerResponse(): array
    {
        return [
            'domain' => 'example.co.za',
            'status' => 'Active',
            'expiryDate' => '2027-10-22',
            'nameservers' => [
                'ns1.example.test',
                'ns2.example.test',
            ],
            'transferLock' => true,
            'restorable' => false,
            'willDomainSuspend' => false,
            'renewBeforeExpiration' => true,
            'registrantEmailAddress' => 'private-provider@example.test',
            'unknownProviderField' => 'discarded',
        ];
    }

    private function service(): ManagedDomainReconciliationService
    {
        return new ManagedDomainReconciliationService(
            new AbsoluteHostingClient(
                endpoint: self::ENDPOINT,
                username: 'reseller@example.com',
                apiKey: '1234567890QWERTYUIOPASDFGHJKL',
            ),
            new DomainReconciliationService,
        );
    }
}
