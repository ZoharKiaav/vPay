<?php

namespace Tests\Feature\Extensions\Servers;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\ProcessPaidInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RegistrarPaidInvoiceLifecycleTest extends TestCase
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

    public function test_partial_payment_does_not_dispatch_registrar_creation(): void
    {
        [$invoice, $service] = $this->registrarInvoice();

        ExtensionHelper::addPayment(
            $invoice->id,
            'FixtureGateway',
            60.00,
            transactionId: 'fixture-partial-payment',
        );

        $invoice->refresh();
        $service->refresh();

        $this->assertSame('pending', $invoice->status);
        $this->assertSame(Service::STATUS_PENDING, $service->status);

        Queue::assertNotPushed(CreateJob::class);
        Http::assertNothingSent();
    }

    public function test_full_payment_dispatches_registrar_creation_once(): void
    {
        [$invoice, $service] = $this->registrarInvoice();

        ExtensionHelper::addPayment(
            $invoice->id,
            'FixtureGateway',
            100.00,
            transactionId: 'fixture-full-payment',
        );

        $invoice->refresh();
        $service->refresh();

        $this->assertSame('paid', $invoice->status);
        $this->assertSame(Service::STATUS_ACTIVE, $service->status);

        Queue::assertPushed(
            CreateJob::class,
            fn (CreateJob $job): bool => $job->service->is($service),
        );

        Queue::assertPushed(CreateJob::class, 1);
        Http::assertNothingSent();

        app(ProcessPaidInvoiceService::class)->handle(
            $invoice->fresh(),
        );

        $service->refresh();

        $this->assertSame(Service::STATUS_ACTIVE, $service->status);
        Queue::assertPushed(CreateJob::class, 1);
        Http::assertNothingSent();
    }

    private function registrarInvoice(): array
    {
        $user = User::factory()->create();

        $server = Server::query()->create([
            'name' => 'Absolute Hosting Domains Fixture',
            'extension' => 'AbsoluteHostingDomains',
            'type' => 'server',
            'enabled' => true,
        ]);

        $productFixture = $this->createProduct([
            'server_id' => $server->id,
        ]);

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $productFixture->product->id,
            'plan_id' => $productFixture->plan->id,
            'status' => Service::STATUS_PENDING,
            'price' => 100.00,
            'currency_code' => 'USD',
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'due_at' => now()->addDays(7),
            'currency_code' => 'USD',
        ]);

        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'description' => 'Fixture registrar service',
            'quantity' => 1,
            'price' => 100.00,
        ]);

        return [$invoice->fresh(), $service->fresh()];
    }
}
