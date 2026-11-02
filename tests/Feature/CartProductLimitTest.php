<?php

namespace Tests\Feature;

use App\Livewire\Cart;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartProductLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelled_services_do_not_consume_product_limit(): void
    {
        [$cart, $user, $product] = $this->subject();

        $this->createService($user, $product, Service::STATUS_CANCELLED);

        self::assertSame(
            0,
            $cart->countServicesForProductLimit($user, $product),
        );
    }

    public function test_non_cancelled_services_consume_product_limit(): void
    {
        foreach ([
            Service::STATUS_PENDING,
            Service::STATUS_ACTIVE,
            Service::STATUS_SUSPENDED,
        ] as $status) {
            [$cart, $user, $product] = $this->subject();

            $this->createService($user, $product, $status);

            self::assertSame(
                1,
                $cart->countServicesForProductLimit($user, $product),
                $status,
            );
        }
    }

    public function test_checkout_view_preserves_cycle_and_setup_fee_contract(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2)
            . '/themes/default/views/products/checkout.blade.php',
        );

        self::assertIsString($source);
        self::assertSame(
            1,
            substr_count($source, "'services.billing_cycles.'"),
        );

        $checkoutSource = file_get_contents(
            dirname(__DIR__, 2)
            . '/app/Livewire/Products/Checkout.php',
        );

        self::assertIsString($checkoutSource);

        self::assertStringContainsString(
            "'services.billing_cycles.'",
            $checkoutSource,
        );

        self::assertStringContainsString(
            '$this->hostingCycleLabel',
            $checkoutSource,
        );
        self::assertStringNotContainsString(
            "__('services.billing_cycles.'",
            $source,
        );
        self::assertStringContainsString(
            '$total->setup_fee > 0',
            $source,
        );
        self::assertStringContainsString(
            "__('product.then_after_x'",
            $source,
        );
    }

    private function subject(): array
    {
        $created = $this->createProduct([
            'per_user_limit' => 1,
        ]);

        return [
            new TestableCart,
            User::factory()->create(),
            $created->product,
        ];
    }

    private function createService(
        User $user,
        Product $product,
        string $status,
    ): Service {
        $plan = $product->plans()->firstOrFail();

        return Service::withoutEvents(
            fn () => Service::query()->create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'plan_id' => $plan->id,
                'currency_code' => 'USD',
                'quantity' => 1,
                'price' => '10.00',
                'status' => $status,
            ]),
        );
    }
}

class TestableCart extends Cart
{
    public function countServicesForProductLimit(
        User $user,
        Product $product,
    ): int {
        return $this->existingServiceCountForProduct(
            $user,
            $product,
        );
    }
}
