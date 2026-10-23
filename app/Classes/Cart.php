<?php

namespace App\Classes;

use App\Exceptions\DisplayException;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;

class Cart
{
    public static function getOnce()
    {
        if (!Cookie::has('cart') || !$cart = \App\Models\Cart::where('ulid', Cookie::get('cart'))->first()) {
            return new \App\Models\Cart;
        }

        return $cart->load('items.plan', 'items.product', 'items.product.configOptions.children.plans.prices');
    }

    public static function get()
    {
        return once(fn () => self::getOnce());
    }

    public static function clear()
    {
        if (Cookie::has('cart')) {
            \App\Models\Cart::where('ulid', Cookie::get('cart'))->delete();
            Cookie::queue(Cookie::forget('cart'));
        }
    }

    public static function items()
    {
        return self::get()->items;
    }

    public static function createCart()
    {
        $cart = self::getOnce();
        if (!$cart->exists) {
            $cart->user_id = Auth::id();
            $cart->currency_code = session('currency', session('currency', config('settings.default_currency')));
            $cart->save();
            Cookie::queue('cart', $cart->ulid, 60 * 24 * 30); // 30 days
            $cart = \App\Models\Cart::find($cart->id);
        }

        return $cart;
    }

    protected const DEFAULT_MAX_ITEMS = 15;

    protected const DEFAULT_RATE_LIMIT_MAX_ATTEMPTS = 10;

    protected const DEFAULT_RATE_LIMIT_DECAY_MINUTES = 1;

    public static function add(Product $product, Plan $plan, $configOptions, $checkoutConfig, $quantity = 1, $key = null)
    {
        self::checkRateLimit();

        // Match on key
        $cart = self::createCart();
        self::ensureCartItemLimit($cart, $key);

        $item = $cart->items()->updateOrCreate([
            'id' => $key,
        ], [
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'config_options' => $configOptions,
            'checkout_config' => $checkoutConfig,
            'quantity' => $quantity,
        ]);
        $cart->load('items.plan', 'items.product', 'items.product.configOptions.children.plans.prices');

        if ($cart->coupon_id) {
            // Reapply coupon to the cart
            try {
                self::validateCoupon($cart->coupon->code);
                // Check if any of the items have gotten a discount
                if ($cart->items->filter(fn ($item) => $item->price->hasDiscount())->isEmpty()) {
                    $cart->coupon_id = null;
                    $cart->save();
                }
            } catch (DisplayException $e) {
                // Coupon is invalid, remove it
                $cart->coupon_id = null;
                $cart->save();
            }
        }

        // Return index of the newly added item
        return $item->id;
    }

    /**
     * Persist a hosting cart item and its optional registrar cart item
     * as one atomic operation.
     *
     * @return array{hosting_item_id: int, registrar_item_id: ?int}
     */
    public static function addLinkedHostingDomainItems(
        array $hosting,
        ?array $registrar,
        ?int $hostingKey = null,
        ?int $registrarKey = null,
    ): array {
        self::checkRateLimit();

        return DB::transaction(function () use (
            $hosting,
            $registrar,
            $hostingKey,
            $registrarKey,
        ): array {
            $existingItem = null;

            if ($hostingKey !== null) {
                $existingItem = CartItem::query()
                    ->with('cart')
                    ->find($hostingKey);
            }

            if ($existingItem === null && $registrarKey !== null) {
                $existingItem = CartItem::query()
                    ->with('cart')
                    ->find($registrarKey);
            }

            $cart = $existingItem?->cart ?? self::createCart();

            if (
                $hostingKey !== null
                && !$cart->items()->whereKey($hostingKey)->exists()
            ) {
                throw new \InvalidArgumentException(
                    'The hosting cart item does not belong to the resolved cart.',
                );
            }

            if (
                $registrarKey !== null
                && !$cart->items()->whereKey($registrarKey)->exists()
            ) {
                throw new \InvalidArgumentException(
                    'The registrar cart item does not belong to the resolved cart.',
                );
            }

            $requiredNewItems = 0;

            if (
                $hostingKey === null
                || !$cart->items()->whereKey($hostingKey)->exists()
            ) {
                $requiredNewItems++;
            }

            if (
                $registrar !== null
                && (
                    $registrarKey === null
                    || !$cart->items()->whereKey($registrarKey)->exists()
                )
            ) {
                $requiredNewItems++;
            }

            if (
                $cart->items()->count() + $requiredNewItems
                > self::DEFAULT_MAX_ITEMS
            ) {
                throw new DisplayException(
                    'Your cart cannot contain more than '
                    . self::DEFAULT_MAX_ITEMS
                    . ' items.',
                );
            }

            $hostingItem = $cart->items()->updateOrCreate(
                ['id' => $hostingKey],
                self::linkedItemAttributes($hosting),
            );

            $registrarItem = null;

            if ($registrar !== null) {
                $registrarItem = $cart->items()->updateOrCreate(
                    ['id' => $registrarKey],
                    self::linkedItemAttributes($registrar),
                );
            } elseif ($registrarKey !== null) {
                $cart->items()
                    ->whereKey($registrarKey)
                    ->delete();
            }

            $cart->load(
                'items.plan',
                'items.product',
                'items.product.configOptions.children.plans.prices',
            );

            if ($cart->coupon_id) {
                try {
                    self::validateCoupon($cart->coupon->code);

                    if (
                        $cart->items
                            ->filter(
                                fn ($item) => $item->price->hasDiscount(),
                            )
                            ->isEmpty()
                    ) {
                        $cart->coupon_id = null;
                        $cart->save();
                    }
                } catch (DisplayException) {
                    $cart->coupon_id = null;
                    $cart->save();
                }
            }

            return [
                'hosting_item_id' => $hostingItem->id,
                'registrar_item_id' => $registrarItem?->id,
            ];
        });
    }

    public static function linkedItemId(
        string $linkId,
        string $role,
        ?int $exceptItemId = null,
    ): ?int {
        $linkId = trim($linkId);
        $role = trim($role);

        if ($linkId === '' || $role === '') {
            return null;
        }

        return self::get()
            ->items
            ->when(
                $exceptItemId !== null,
                fn ($items) => $items->where('id', '!=', $exceptItemId),
            )
            ->first(function ($item) use ($linkId, $role): bool {
                $config = (array) ($item->checkout_config ?? []);

                return ($config['hosting_domain_link_id'] ?? null)
                        === $linkId
                    && ($config['hosting_domain_link_role'] ?? null)
                        === $role;
            })
            ?->id;
    }

    public static function removeLinkedHostingDomainItems(
        int $itemId,
    ): void {
        DB::transaction(function () use ($itemId): void {
            $item = CartItem::query()
                ->with('cart')
                ->find($itemId);

            if ($item === null) {
                return;
            }

            $cart = $item->cart;
            $config = (array) ($item->checkout_config ?? []);
            $linkId = trim((string) (
                $config['hosting_domain_link_id'] ?? ''
            ));

            if ($linkId === '') {
                $item->delete();

                return;
            }

            $cart->items()
                ->get()
                ->filter(function ($candidate) use ($linkId): bool {
                    $candidateConfig = (array) (
                        $candidate->checkout_config ?? []
                    );

                    return (
                        $candidateConfig['hosting_domain_link_id']
                            ?? null
                    ) === $linkId;
                })
                ->each
                ->delete();
        });
    }

    private static function linkedItemAttributes(
        array $payload,
    ): array {
        foreach ([
            'product',
            'plan',
            'config_options',
            'checkout_config',
            'quantity',
        ] as $requiredKey) {
            if (!array_key_exists($requiredKey, $payload)) {
                throw new \InvalidArgumentException(
                    "Linked cart payload is missing {$requiredKey}.",
                );
            }
        }

        if (
            !$payload['product'] instanceof Product
            || !$payload['plan'] instanceof Plan
        ) {
            throw new \InvalidArgumentException(
                'Linked cart payload product and plan are invalid.',
            );
        }

        if (
            (int) $payload['plan']->priceable_id
            !== (int) $payload['product']->id
            || $payload['plan']->priceable_type
            !== $payload['product']::class
        ) {
            throw new \InvalidArgumentException(
                'Linked cart plan does not belong to its product.',
            );
        }

        return [
            'product_id' => $payload['product']->id,
            'plan_id' => $payload['plan']->id,
            'config_options' => $payload['config_options'],
            'checkout_config' => $payload['checkout_config'],
            'quantity' => max(1, (int) $payload['quantity']),
        ];
    }

    protected static function ensureCartItemLimit($cart, $key = null)
    {
        $existingItem = $key ? $cart->items()->where('id', $key)->exists() : false;

        if (!$existingItem && $cart->items()->count() >= self::DEFAULT_MAX_ITEMS) {
            throw new DisplayException('Your cart cannot contain more than ' . self::DEFAULT_MAX_ITEMS . ' items.');
        }
    }

    protected static function checkRateLimit()
    {
        $key = self::resolveRateLimiterKey();
        $maxAttempts = self::DEFAULT_RATE_LIMIT_MAX_ATTEMPTS;
        $decayMinutes = self::DEFAULT_RATE_LIMIT_DECAY_MINUTES;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw new DisplayException('You are adding items too quickly. Please wait a moment and try again.');
        }

        RateLimiter::hit($key, $decayMinutes * 60);
    }

    protected static function resolveRateLimiterKey()
    {
        if (Auth::check()) {
            return sprintf('cart-add:user:%s', Auth::id());
        }

        return sprintf('cart-add:ip:%s', request()->ip());
    }

    public static function remove($index)
    {
        $cart = self::get();
        $item = $cart->items()->where('id', $index)->first();
        if ($item) {
            $item->delete(); // We also want to trigger Eloquent events
        }
        $cart->load('items.plan', 'items.product', 'items.product.configOptions.children.plans.prices');
    }

    public static function updateQuantity($index, $quantity)
    {
        $cart = self::get();
        if ($item = $cart->items()->where('id', $index)->first()) {
            if ($item->product->allow_quantity !== 'combined') {
                return;
            }
        } else {
            return;
        }

        if ($quantity < 1) {
            self::remove($index);

            return;
        }
        $item->quantity = $quantity;
        $item->save();

        $cart->load('items');
    }

    /**
     * Validate if a coupon is valid for the current user and cart
     *
     * @param  string  $coupon_code
     * @return Coupon
     *
     * @throws DisplayException
     */
    public static function validateCoupon($coupon_code)
    {
        return DB::transaction(function () use ($coupon_code) {
            $coupon = Coupon::where('code', $coupon_code)->lockForUpdate()->first();

            if (!$coupon) {
                throw new DisplayException('Coupon code not found');
            }

            if ($coupon->expires_at && $coupon->expires_at->isPast()) {
                throw new DisplayException('Coupon code has expired');
            }
            if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
                throw new DisplayException('Coupon code is not active yet');
            }
            if ($coupon->max_uses && $coupon->services()->count() >= $coupon->max_uses) {
                throw new DisplayException('Coupon code has reached its maximum uses');
            }
            if (Auth::check() && $coupon->hasExceededMaxUsesPerUser(Auth::id())) {
                throw new DisplayException('You have already used this coupon the maximum number of times allowed');
            }
            if ($coupon->products->isNotEmpty()) {
                $cart = self::get();
                $applicable = false;
                foreach ($cart->items as $item) {
                    if ($coupon->products->contains($item->product_id)) {
                        $applicable = true;
                        break;
                    }
                }
                if (!$applicable) {
                    throw new DisplayException('Coupon code is not valid for any items in your cart');
                }
            }

            return $coupon;
        });
    }

    public static function applyCoupon($code)
    {
        if (RateLimiter::tooManyAttempts('apply_coupon_' . request()->ip(), 5)) {
            throw new DisplayException('Too many attempts. Please try again later.');
        }

        RateLimiter::hit('apply_coupon_' . request()->ip());

        $coupon = self::validateCoupon($code);

        $wasSuccessful = false;
        $cart = self::createCart();
        $cart->coupon_id = $coupon->id;
        $cart->save();

        // Check if any of the items have gotten a discount, if empty also set succesful because it's valid for future use (will get rechecked on checkout
        if ($cart->items->filter(fn ($item) => $item->price->hasDiscount())->isNotEmpty() || $cart->items->isEmpty()) {
            $wasSuccessful = true;
        } else {
            $cart->coupon_id = null;
        }

        if ($wasSuccessful) {
            $cart->save();

            return $cart;
        } else {
            $cart->coupon_id = null;
            $cart->save();
            throw new DisplayException('Coupon code is not valid for any items in your cart');
        }
    }

    /**
     * Validates and refreshes the coupon in the session
     *
     * @return bool True if coupon is valid, false otherwise
     */
    public static function validateAndRefreshCoupon()
    {
        if (!self::get()->coupon_id || !self::get()->coupon) {
            return true;
        }

        try {
            $coupon = self::get()->coupon;
            self::validateCoupon($coupon->code);

            return true;
        } catch (DisplayException $e) {
            // Coupon is invalid, remove it
            self::removeCoupon();

            return false;
        }
    }

    public static function removeCoupon()
    {
        self::get()->update(['coupon_id' => null]);
        self::get()->load('coupon');
    }
}
