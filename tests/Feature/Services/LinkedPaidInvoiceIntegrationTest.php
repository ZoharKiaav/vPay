<?php

namespace Tests\Feature\Services;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Models\HostingDomainServiceLink;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\ProcessPaidInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LinkedPaidInvoiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'app.cipher' => 'AES-256-CBC',
        ]);

        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_partial_payment_dispatches_no_linked_jobs(): void
    {
        [
            $invoice,
            $domainService,
            $hostingService,
        ] = $this->linkedInvoice();

        ExtensionHelper::addPayment(
            $invoice->id,
            'FixtureGateway',
            100.00,
            transactionId: 'linked-partial-payment',
        );

        $this->assertSame(
            Invoice::STATUS_PENDING,
            $invoice->fresh()->status,
        );

        $this->assertSame(
            Service::STATUS_PENDING,
            $domainService->fresh()->status,
        );

        $this->assertSame(
            Service::STATUS_PENDING,
            $hostingService->fresh()->status,
        );

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_full_payment_chains_domain_before_hosting(): void
    {
        [
            $invoice,
            $domainService,
            $hostingService,
        ] = $this->linkedInvoice();

        ExtensionHelper::addPayment(
            $invoice->id,
            'FixtureGateway',
            200.00,
            transactionId: 'linked-full-payment',
        );

        $this->assertSame(
            Invoice::STATUS_PAID,
            $invoice->fresh()->status,
        );

        Queue::assertPushedWithChain(
            CreateJob::class,
            [
                CreateJob::class,
            ],
            fn (CreateJob $job): bool => $job->service->is($domainService),
        );

        Queue::assertPushed(
            CreateJob::class,
            fn (CreateJob $job): bool => $job->service->is($domainService),
        );

        Queue::assertNotPushed(
            CreateJob::class,
            fn (CreateJob $job): bool => $job->service->is($hostingService),
        );

        $this->assertSame(
            Service::STATUS_ACTIVE,
            $domainService->fresh()->status,
        );

        $this->assertSame(
            Service::STATUS_ACTIVE,
            $hostingService->fresh()->status,
        );

        Http::assertNothingSent();
    }

    public function test_reprocessing_paid_invoice_adds_no_jobs(): void
    {
        [
            $invoice,
            $domainService,
            $hostingService,
        ] = $this->linkedInvoice();

        ExtensionHelper::addPayment(
            $invoice->id,
            'FixtureGateway',
            200.00,
            transactionId: 'linked-repeat-payment',
        );

        Queue::assertPushed(CreateJob::class, 1);

        app(ProcessPaidInvoiceService::class)->handle(
            $invoice->fresh(),
        );

        Queue::assertPushed(CreateJob::class, 1);

        $this->assertSame(
            Service::STATUS_ACTIVE,
            $domainService->fresh()->status,
        );

        $this->assertSame(
            Service::STATUS_ACTIVE,
            $hostingService->fresh()->status,
        );

        Http::assertNothingSent();
    }

    public function test_external_domain_hosting_uses_standalone_job(): void
    {
        [
            $invoice,
            $hostingService,
        ] = $this->externalDomainInvoice();

        ExtensionHelper::addPayment(
            $invoice->id,
            'FixtureGateway',
            100.00,
            transactionId: 'external-domain-payment',
        );

        $this->assertSame(
            Invoice::STATUS_PAID,
            $invoice->fresh()->status,
        );

        Queue::assertPushedWithoutChain(
            CreateJob::class,
            fn (CreateJob $job): bool => $job->service->is($hostingService),
        );

        Queue::assertPushed(CreateJob::class, 1);

        $this->assertSame(
            Service::STATUS_ACTIVE,
            $hostingService->fresh()->status,
        );

        $this->assertDatabaseCount(
            'hosting_domain_service_links',
            0,
        );

        Http::assertNothingSent();
    }

    public function test_hosting_exists_only_as_the_second_chain_job(): void
    {
        [
            $invoice,
            $domainService,
            $hostingService,
        ] = $this->linkedInvoice();

        ExtensionHelper::addPayment(
            $invoice->id,
            'FixtureGateway',
            200.00,
            transactionId: 'linked-chain-boundary',
        );

        Queue::assertPushedWithChain(
            CreateJob::class,
            [
                CreateJob::class,
            ],
            fn (CreateJob $job): bool => $job->service->is($domainService),
        );

        Queue::assertNotPushed(
            CreateJob::class,
            fn (CreateJob $job): bool => $job->service->is($hostingService),
        );

        Queue::assertPushed(CreateJob::class, 1);
        Http::assertNothingSent();
    }

    private function linkedInvoice(): array
    {
        $user = User::factory()->create();

        $order = Order::query()->create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
        ]);

        $domainService = $this->service(
            $user,
            $order,
            $this->server('AbsoluteHostingDomains'),
            100.00,
        );

        $hostingService = $this->service(
            $user,
            $order,
            $this->server('Hestia'),
            100.00,
        );

        HostingDomainServiceLink::query()->create([
            'hosting_service_id' => $hostingService->id,
            'domain_service_id' => $domainService->id,
        ]);

        $invoice = $this->invoice($user);

        $this->invoiceItem(
            $invoice,
            $domainService,
            'Fixture linked domain service',
            100.00,
        );

        $this->invoiceItem(
            $invoice,
            $hostingService,
            'Fixture linked hosting service',
            100.00,
        );

        return [
            $invoice->fresh(),
            $domainService->fresh(),
            $hostingService->fresh(),
        ];
    }

    private function externalDomainInvoice(): array
    {
        $user = User::factory()->create();

        $order = Order::query()->create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
        ]);

        $hostingService = $this->service(
            $user,
            $order,
            $this->server('Hestia'),
            100.00,
        );

        $hostingService->properties()->create([
            'key' => 'domain_mode',
            'value' => 'external',
        ]);

        $hostingService->properties()->create([
            'key' => 'domain',
            'value' => 'existing.example.com',
        ]);

        $invoice = $this->invoice($user);

        $this->invoiceItem(
            $invoice,
            $hostingService,
            'Fixture external-domain hosting service',
            100.00,
        );

        return [
            $invoice->fresh(),
            $hostingService->fresh(),
        ];
    }

    private function service(
        User $user,
        Order $order,
        Server $server,
        float $price,
    ): Service {
        $fixture = $this->createProduct([
            'server_id' => $server->id,
        ]);

        return Service::factory()->create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_PENDING,
            'price' => $price,
            'currency_code' => 'USD',
        ]);
    }

    private function invoice(User $user): Invoice
    {
        return Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDays(7),
            'currency_code' => 'USD',
        ]);
    }

    private function invoiceItem(
        Invoice $invoice,
        Service $service,
        string $description,
        float $price,
    ): void {
        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'description' => $description,
            'quantity' => 1,
            'price' => $price,
        ]);
    }

    private function server(string $extension): Server
    {
        return Server::query()->create([
            'name' => $extension . ' invoice fixture',
            'extension' => $extension,
            'type' => 'server',
            'enabled' => true,
        ]);
    }
}
