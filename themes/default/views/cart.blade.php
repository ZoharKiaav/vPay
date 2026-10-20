<div class="container mt-14">
    <div class="flex flex-col md:grid md:grid-cols-4 gap-8">
        <div class="flex flex-col col-span-3 gap-4">
            @if (Cart::items()->count() === 0)
            <h1 class="text-2xl font-semibold">
                {{ __('product.empty_cart') }}
            </h1>
            @endif
            @foreach (Cart::items() as $item)
            <div class="flex flex-row justify-between w-full bg-background-secondary p-3 rounded-md border border-neutral">
                <div class="flex flex-col gap-1">
                    <h2 class="text-2xl font-semibold">
                        {{ $item->product->name }}
                    </h2>
                    @if (count($item->config_options) > 0)
                    <div class="text-sm text-primary-100/80">
                        @foreach ($item->config_options as $option)
                        <div>
                            <span class="font-medium">
                                {{ $option['option_name'] }}:
                            </span>
                            {{ $option['value_name'] }}
                        </div>
                        @endforeach
                    </div>
                    @endif

                    @php
                        $checkoutConfig = (array) ($item->checkout_config ?? []);

                        $domainSld = trim((string) (
                            $checkoutConfig['domain_sld'] ?? ''
                        ));

                        $domainTld = '.' . strtolower(ltrim(
                            trim((string) (
                                $checkoutConfig['domain_tld'] ?? ''
                            )),
                            '.',
                        ));

                        $isDomainItem =
                            $domainSld !== ''
                            && $domainTld !== '.';

                        $canonicalDomain = $isDomainItem
                            ? strtolower($domainSld) . $domainTld
                            : null;

                        $domainAction = strtolower(trim((string) (
                            $checkoutConfig['domain_action']
                                ?? 'register'
                        )));

                        $registrationPeriod = max(
                            1,
                            (int) (
                                $checkoutConfig['registration_period']
                                    ?? 1
                            ),
                        );

                        $usesCustomNameservers = filter_var(
                            $checkoutConfig['use_custom_nameservers']
                                ?? false,
                            FILTER_VALIDATE_BOOL,
                        );

                        $nameservers = collect([
                            $checkoutConfig['nameserver_1'] ?? null,
                            $checkoutConfig['nameserver_2'] ?? null,
                            $checkoutConfig['nameserver_3'] ?? null,
                            $checkoutConfig['nameserver_4'] ?? null,
                            $checkoutConfig['nameserver_5'] ?? null,
                        ])->map(
                            static fn (mixed $nameserver): string =>
                                trim((string) $nameserver),
                        )->filter()->values();
                    @endphp

                    @if ($isDomainItem)
                    <div class="mt-3 rounded-md border border-neutral bg-background px-3 py-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-primary-100/60">
                            Domain details
                        </div>

                        <dl class="mt-2 grid grid-cols-1 gap-2 text-sm sm:grid-cols-[9rem_1fr]">
                            <dt class="text-primary-100/70">
                                Domain
                            </dt>
                            <dd class="font-semibold break-all text-primary-100">
                                {{ $canonicalDomain }}
                            </dd>

                            <dt class="text-primary-100/70">
                                Action
                            </dt>
                            <dd class="text-primary-100">
                                {{ $domainAction === 'transfer' ? 'Domain transfer' : 'New registration' }}
                            </dd>

                            <dt class="text-primary-100/70">
                                Period
                            </dt>
                            <dd class="text-primary-100">
                                {{ $registrationPeriod }}
                                {{ $registrationPeriod === 1 ? 'year' : 'years' }}
                            </dd>

                            <dt class="text-primary-100/70">
                                DNS
                            </dt>
                            <dd class="text-primary-100">
                                {{ $usesCustomNameservers ? 'Custom nameservers' : 'vKloud nameservers' }}
                            </dd>

                            @if ($nameservers->isNotEmpty())
                            <dt class="text-primary-100/70">
                                Nameservers
                            </dt>
                            <dd class="break-words text-primary-100">
                                {{ $nameservers->join(', ') }}
                            </dd>
                            @endif

                            @if (
                                $domainAction === 'transfer'
                                && filled(
                                    $checkoutConfig['epp_code'] ?? null
                                )
                            )
                            <dt class="text-primary-100/70">
                                Transfer code
                            </dt>
                            <dd class="text-primary-100">
                                Added
                            </dd>
                            @endif
                        </dl>
                    </div>
                    @endif
                </div>
                <div class="flex flex-col justify-between items-end gap-4">
                    <h3 class="text-xl font-semibold p-1">
                        {{ $item->price->format($item->price->total * $item->quantity) }} @if ($item->quantity > 1)
                        ({{ $item->price }} each)
                        @endif
                    </h3>
                    <div class="flex flex-row gap-2">
                        @if ($item->product->allow_quantity == 'combined')
                        <div class="flex flex-row gap-1 items-center mr-4">
                            <x-button.secondary
                                wire:click="updateQuantity({{ $item->id }}, {{ $item->quantity - 1 }})"
                                class="h-full !w-fit">
                                -
                            </x-button.secondary>
                            <x-form.input class="h-10 text-center" disabled divClass="!mt-0 !w-14" value="{{ $item->quantity }}" name="quantity" />
                            <x-button.secondary
                                wire:click="updateQuantity({{ $item->id }}, {{ $item->quantity + 1 }});"
                                class="h-full !w-fit">
                                +
                            </x-button.secondary>
                        </div>
                        @endif
                        <a href="{{ route('products.checkout', [$item->product->category, $item->product, 'edit' => $item->id]) }}"
                            wire:navigate>
                            <x-button.primary class="h-fit w-fit">
                                {{ __('product.edit') }}
                            </x-button.primary>
                        </a>
                        <x-button.danger wire:click="removeProduct({{ $item->id }})" class="h-fit !w-fit">
                            <x-loading target="removeProduct({{ $item->id }})" />
                            <div wire:loading.remove wire:target="removeProduct({{ $item->id }})">
                                {{ __('product.remove') }}
                            </div>
                        </x-button.danger>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
        <div class="flex flex-col gap-4">
            @if (Cart::items()->count() > 0)
            <div class="flex flex-col gap-2 w-full col-span-1 bg-background-secondary p-3 rounded-md border border-neutral">
                <h2 class="text-2xl font-semibold mb-3">
                    {{ __('product.order_summary') }}
                </h2>
                <div class="font-semibold flex items-end gap-2">
                    @if(!$coupon)
                    <x-form.input wire:model="coupon" name="coupon" label="Coupon" />
                    <x-button.primary wire:click="applyCoupon" class="h-fit !w-fit mb-0.5" wire:loading.attr="disabled">
                        <x-loading target="applyCoupon" />
                        <div wire:loading.remove wire:target="applyCoupon">
                            {{ __('product.apply') }}
                        </div>
                    </x-button.primary>
                    @else
                    <div class="flex justify-between items-center w-full">
                        <h4 class="text-center w-full">{{ $coupon->code }}</h4>
                        <x-button.secondary wire:click="removeCoupon" class="h-fit !w-fit">
                            {{ __('product.remove') }}
                        </x-button.secondary>
                    </div>
                    @endif
                </div>
                <div class="font-semibold flex justify-between">
                    <h4>{{ __('invoices.subtotal') }}:</h4> {{ $total->format($total->subtotal) }}
                </div>
                @if ($total->tax > 0)
                <div class="font-semibold flex justify-between">
                    <h4>{{ \App\Classes\Settings::tax()->name }} ({{ \App\Classes\Settings::tax()->rate }}%):</h4> {{ $total->format($total->tax) }}
                </div>
                @endif
                <div class="text-lg font-semibold flex justify-between mt-1">
                    <h4>{{ __('invoices.total') }}:</h4> {{ $total->format($total->total) }}
                </div>

                <div class="flex flex-col gap-2 w-full col-span-1">
                    @if(config('settings.tos'))
                    <x-form.checkbox wire:model="tos" name="tos">
                        {{ __('product.tos') }}
                        <a href="{{ config('settings.tos') }}" target="_blank" class="text-primary hover:text-primary/80">
                            {{ __('product.tos_link') }}
                        </a>
                    </x-form.checkbox>
                    @endif

                    <div class="flex flex-row justify-end gap-2">
                        <x-button.primary wire:click="checkout" class="h-fit" wire:loading.attr="disabled">
                            <x-loading target="checkout" />
                            <div wire:loading.remove wire:target="checkout">
                                {{ __('product.checkout') }}
                            </div>
                        </x-button.primary>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>
