<?php

namespace Tests\Feature\Services;

use App\Livewire\Cart;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class CheckoutLinkedServiceCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_one_link_for_a_complete_pair(): void
    {
        [$hostingService, $domainService] =
            $this->servicesInSameOrder();

        $this->subject()->createLinks([
            'fixture-link-id' => [
                'domain_mode' => 'register',
                'hosting' => $hostingService,
                'registrar' => $domainService,
            ],
        ]);

        $this->assertDatabaseCount(
            'hosting_domain_service_links',
            1,
        );

        $this->assertDatabaseHas(
            'hosting_domain_service_links',
            [
                'hosting_service_id' =>
                    $hostingService->id,
                'domain_service_id' =>
                    $domainService->id,
            ],
        );
    }

    public function test_external_domain_hosting_creates_no_link(): void
    {
        [$hostingService] = $this->servicesInSameOrder();

        $this->subject()->createLinks([
            'external-link-id' => [
                'domain_mode' => 'external',
                'hosting' => $hostingService,
                'registrar' => null,
            ],
        ]);

        $this->assertDatabaseCount(
            'hosting_domain_service_links',
            0,
        );
    }

    public function test_missing_domain_service_is_rejected(): void
    {
        [$hostingService] = $this->servicesInSameOrder();

        $this->expectException(LogicException::class);

        $this->expectExceptionMessage(
            'A linked hosting-domain purchase must contain exactly one hosting service and one domain service.',
        );

        $this->subject()->createLinks([
            'incomplete-link-id' => [
                'domain_mode' => 'register',
                'hosting' => $hostingService,
                'registrar' => null,
            ],
        ]);
    }

    public function test_missing_hosting_service_is_rejected(): void
    {
        [, $domainService] = $this->servicesInSameOrder();

        $this->expectException(LogicException::class);

        $this->expectExceptionMessage(
            'A linked hosting-domain purchase must contain exactly one hosting service and one domain service.',
        );

        $this->subject()->createLinks([
            'incomplete-link-id' => [
                'domain_mode' => 'register',
                'hosting' => null,
                'registrar' => $domainService,
            ],
        ]);
    }

    private function servicesInSameOrder(): array
    {
        $user = User::factory()->create();

        $order = Order::query()->create([
            'user_id' => $user->id,
            'currency_code' => 'ZAR',
        ]);

        return [
            $this->service($user, $order),
            $this->service($user, $order),
        ];
    }

    private function service(
        User $user,
        Order $order,
    ): Service {
        return Service::factory()->create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'status' => Service::STATUS_PENDING,
            'currency_code' => 'ZAR',
        ]);
    }

    private function subject(): TestableCheckoutCart
    {
        return new TestableCheckoutCart;
    }
}

class TestableCheckoutCart extends Cart
{
    public function createLinks(
        array $linkedServices,
    ): void {
        $this->createHostingDomainServiceLinks(
            $linkedServices,
        );
    }
}