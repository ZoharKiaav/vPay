<?php

namespace Tests\Feature\Classes;

use App\Classes\Cart;
use App\Models\Cart as CartModel;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Services\HostingDomainCartPayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class LinkedHostingDomainCartTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_two_linked_cart_items_atomically(): void
    {
        [$hostingProduct, $hostingPlan] = $this->pricedProduct(
            'Hosting',
        );

        [$registrarProduct, $registrarPlan] = $this->pricedProduct(
            'Domain registration',
        );

        $linkId = (string) Str::uuid();

        $result = Cart::addLinkedHostingDomainItems(
            $this->payload(
                $hostingProduct,
                $hostingPlan,
                $linkId,
                HostingDomainCartPayloadBuilder::ROLE_HOSTING,
            ),
            $this->payload(
                $registrarProduct,
                $registrarPlan,
                $linkId,
                HostingDomainCartPayloadBuilder::ROLE_REGISTRAR,
            ),
        );

        $this->assertNotNull($result['hosting_item_id']);
        $this->assertNotNull($result['registrar_item_id']);

        $items = CartModel::query()
            ->firstOrFail()
            ->items()
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $items);

        $this->assertSame(
            [
                HostingDomainCartPayloadBuilder::ROLE_HOSTING,
                HostingDomainCartPayloadBuilder::ROLE_REGISTRAR,
            ],
            $items
                ->map(
                    fn ($item) => $item->checkout_config[
                        HostingDomainCartPayloadBuilder::LINK_ROLE_KEY
                    ],
                )
                ->all(),
        );

        $this->assertSame(
            [$linkId],
            $items
                ->pluck('checkout_config')
                ->map(
                    fn (array $config) => $config[
                        HostingDomainCartPayloadBuilder::LINK_ID_KEY
                    ],
                )
                ->unique()
                ->values()
                ->all(),
        );
    }

    public function test_external_mode_adds_only_the_hosting_item(): void
    {
        [$hostingProduct, $hostingPlan] = $this->pricedProduct(
            'Hosting',
        );

        $linkId = (string) Str::uuid();

        $result = Cart::addLinkedHostingDomainItems(
            $this->payload(
                $hostingProduct,
                $hostingPlan,
                $linkId,
                HostingDomainCartPayloadBuilder::ROLE_HOSTING,
            ),
            null,
        );

        $this->assertNotNull($result['hosting_item_id']);
        $this->assertNull($result['registrar_item_id']);

        $this->assertSame(
            1,
            CartModel::query()->firstOrFail()->items()->count(),
        );
    }

    public function test_switching_to_external_removes_the_linked_registrar_item(): void
    {
        [$hostingProduct, $hostingPlan] = $this->pricedProduct(
            'Hosting',
        );

        [$registrarProduct, $registrarPlan] = $this->pricedProduct(
            'Domain registration',
        );

        $linkId = (string) Str::uuid();

        $initial = Cart::addLinkedHostingDomainItems(
            $this->payload(
                $hostingProduct,
                $hostingPlan,
                $linkId,
                HostingDomainCartPayloadBuilder::ROLE_HOSTING,
            ),
            $this->payload(
                $registrarProduct,
                $registrarPlan,
                $linkId,
                HostingDomainCartPayloadBuilder::ROLE_REGISTRAR,
            ),
        );

        $updated = Cart::addLinkedHostingDomainItems(
            $this->payload(
                $hostingProduct,
                $hostingPlan,
                $linkId,
                HostingDomainCartPayloadBuilder::ROLE_HOSTING,
            ),
            null,
            $initial['hosting_item_id'],
            $initial['registrar_item_id'],
        );

        $this->assertSame(
            $initial['hosting_item_id'],
            $updated['hosting_item_id'],
        );

        $this->assertNull($updated['registrar_item_id']);

        $this->assertSame(
            1,
            CartModel::query()->firstOrFail()->items()->count(),
        );
    }

    public function test_removing_either_linked_item_removes_the_pair(): void
    {
        [$hostingProduct, $hostingPlan] = $this->pricedProduct(
            'Hosting',
        );

        [$registrarProduct, $registrarPlan] = $this->pricedProduct(
            'Domain registration',
        );

        $linkId = (string) Str::uuid();

        $result = Cart::addLinkedHostingDomainItems(
            $this->payload(
                $hostingProduct,
                $hostingPlan,
                $linkId,
                HostingDomainCartPayloadBuilder::ROLE_HOSTING,
            ),
            $this->payload(
                $registrarProduct,
                $registrarPlan,
                $linkId,
                HostingDomainCartPayloadBuilder::ROLE_REGISTRAR,
            ),
        );

        Cart::removeLinkedHostingDomainItems(
            $result['registrar_item_id'],
        );

        $this->assertSame(
            0,
            CartModel::query()->firstOrFail()->items()->count(),
        );
    }

    public function test_it_rolls_back_when_the_registrar_plan_is_invalid(): void
    {
        [$hostingProduct, $hostingPlan] = $this->pricedProduct(
            'Hosting',
        );

        [$registrarProduct] = $this->pricedProduct(
            'Domain registration',
        );

        [, $foreignPlan] = $this->pricedProduct(
            'Unrelated product',
        );

        $linkId = (string) Str::uuid();

        try {
            Cart::addLinkedHostingDomainItems(
                $this->payload(
                    $hostingProduct,
                    $hostingPlan,
                    $linkId,
                    HostingDomainCartPayloadBuilder::ROLE_HOSTING,
                ),
                $this->payload(
                    $registrarProduct,
                    $foreignPlan,
                    $linkId,
                    HostingDomainCartPayloadBuilder::ROLE_REGISTRAR,
                ),
            );

            $this->fail(
                'An invalid registrar plan should have been rejected.',
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Linked cart plan does not belong to its product.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, CartModel::query()->count());
    }

    private function payload(
        Product $product,
        Plan $plan,
        string $linkId,
        string $role,
    ): array {
        return [
            'product' => $product,
            'plan' => $plan,
            'config_options' => [],
            'checkout_config' => [
                HostingDomainCartPayloadBuilder::LINK_ID_KEY => $linkId,
                HostingDomainCartPayloadBuilder::LINK_ROLE_KEY => $role,
            ],
            'quantity' => 1,
        ];
    }

    private function pricedProduct(
        string $name,
    ): array {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'ZAR'],
            [
                'name' => 'South African Rand',
                'prefix' => 'R',
                'suffix' => null,
                'exchange_rate' => 1,
                'default' => true,
            ],
        );

        session(['currency' => $currency->code]);

        $product = Product::factory()->create([
            'name' => $name,
        ]);

        $plan = $product->plans()->create([
            'name' => $name . ' yearly',
            'type' => 'recurring',
            'billing_period' => 1,
            'billing_unit' => 'year',
            'sort' => 1,
        ]);

        Price::query()->create([
            'plan_id' => $plan->id,
            'currency_code' => $currency->code,
            'price' => 100,
            'setup_fee' => 0,
        ]);

        return [
            $product->fresh('plans.prices.currency'),
            $plan->fresh('prices.currency'),
        ];
    }
}
