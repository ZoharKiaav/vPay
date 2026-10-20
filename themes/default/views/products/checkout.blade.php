<div
    class="container mt-14 flex flex-col gap-6 md:grid md:grid-cols-4"
    x-data
    x-init="
        if (@js((bool) $cartProductKey)) {
            $nextTick(() => {
                document
                    .getElementById('registrar-domain-controls')
                    ?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center',
                    });
            });
        }
    "
>
    <div class="flex flex-col gap-4 w-full col-span-3">
        <h1 class="text-3xl font-bold">{{ $product->name }}</h1>
        <div class="flex flex-row w-full gap-4">
            @if ($product->image)
                <img src="{{ Storage::url($product->image) }}" alt="{{ $product->name }}" class="max-w-40">
            @endif
            <div class="max-h-28 overflow-y-auto w-full">
                <article class="prose dark:prose-invert prose-sm">
                    {!! $product->description !!}
                </article>
            </div>
        </div>
        @if ($product->availablePlans()->count() > 1)
            <x-form.select wire:model.live="plan_id" name="plan_id" label="Select a plan">
                @foreach ($product->availablePlans() as $availablePlan)
                    <option value="{{ $availablePlan->id }}">
                        {{ $availablePlan->name }} -
                        {{ $availablePlan->price()->formatted->price }}
                        @if ($availablePlan->price()->has_setup_fee)
                            + {{ $availablePlan->price()->formatted->setup_fee }} {{ __('product.setup_fee') }}
                        @endif
                    </option>
                @endforeach
            </x-form.select>
        @endif

        @foreach ($product->configOptions as $configOption)
            @php
                $showPriceTag = $configOption->children->filter(fn ($value) => !$value->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit)->is_free)->count() > 0;
            @endphp
            <x-form.configoption :config="$configOption" :name="'configOptions.' . $configOption->id" :showPriceTag="$showPriceTag" :plan="$plan">
                @if ($configOption->type == 'select')
                    @foreach ($configOption->children as $configOptionValue)
                        <option value="{{ $configOptionValue->id }}">
                            {{ $configOptionValue->name }}
                            {{ ($showPriceTag && $configOptionValue->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit)->available) ? ' - ' . $configOptionValue->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit) : '' }}
                        </option>
                    @endforeach
                @elseif($configOption->type == 'radio')
                    @foreach ($configOption->children as $configOptionValue)
                        <div class="flex items-center gap-2">
                            <input type="radio" id="{{ $configOptionValue->id }}" name="{{ $configOption->id }}"
                                wire:model.live="configOptions.{{ $configOption->id }}"
                                value="{{ $configOptionValue->id }}" />
                            <label for="{{ $configOptionValue->id }}">
                                {{ $configOptionValue->name }}
                                {{ ($showPriceTag && $configOptionValue->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit)->available) ? ' - ' . $configOptionValue->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit) : '' }}
                            </label>
                        </div>
                    @endforeach
                @endif
            </x-form.configoption>
        @endforeach
        @foreach ($this->getCheckoutConfig() as $configOption)
            @php $configOption = (object) $configOption; @endphp
            @if ($configOption->visible ?? true)
                @if ($configOption->name === 'use_custom_nameservers')
                    <div class="mt-3 rounded-lg border border-neutral bg-background-secondary/40 px-4 py-4">
                        <h2 class="text-lg font-semibold text-primary-100">
                            DNS settings
                        </h2>
                        <p class="mt-1 text-sm text-primary-100/75">
                            Keep the default vKloud nameservers, or choose custom nameservers for this domain.
                        </p>
                    </div>
                @endif

                @if ($configOption->name === 'epp_code')
                    <div class="mt-3 rounded-lg border border-neutral bg-background-secondary/40 px-4 py-4">
                        <h2 class="text-lg font-semibold text-primary-100">
                            Transfer authorisation
                        </h2>
                        <p class="mt-1 text-sm text-primary-100/75">
                            Enter the EPP or transfer code supplied by the current registrar.
                        </p>
                    </div>
                @endif

                @if ($configOption->name === 'domain_sld')
                    <div
                        id="registrar-domain-controls"
                        class="registrar-domain-controls grid grid-cols-1 gap-4 rounded-lg border border-neutral bg-background-secondary/40 p-4 md:grid-cols-2"
                    >
                @endif

                <div
                    @class([
                        'min-w-0',
                        'rounded-lg border border-neutral bg-background-secondary/40 p-4' => in_array(
                            $configOption->name,
                            ['use_custom_nameservers', 'epp_code'],
                            true,
                        ),
                    ])
                >
                <x-form.configoption :config="$configOption" :name="'checkoutConfig.' . $configOption->name">
                @if ($configOption->type == 'select')
                    @foreach ($configOption->options as $configOptionValue => $configOptionValueName)
                        <option value="{{ $configOptionValue }}">
                            {{ $configOptionValueName }}
                        </option>
                    @endforeach
                @elseif($configOption->type == 'radio')
                    @foreach ($configOption->options as $configOptionValue => $configOptionValueName)
                        <div class="flex items-center gap-2">
                            <input type="radio" id="{{ $configOptionValue }}" name="{{ $configOption->name }}"
                                wire:model.live="checkoutConfig.{{ $configOption->name }}"
                                value="{{ $configOptionValue }}" />
                            <label for="{{ $configOptionValue }}">
                                {{ $configOptionValueName }}
                            </label>
                        </div>
                    @endforeach
                @endif
                </x-form.configoption>
                </div>

                @if ($configOption->name === 'domain_tld')
                    </div>

                    @php
                        $domainInput = trim((string) (
                            $checkoutConfig['domain_sld'] ?? ''
                        ));

                        $domainTld = trim((string) (
                            $checkoutConfig['domain_tld'] ?? ''
                        ));

                        $domainInputWithoutDot = rtrim(
                            strtolower($domainInput),
                            '.',
                        );

                        $tldWithoutDot = ltrim(
                            strtolower($domainTld),
                            '.',
                        );

                        $canonicalDomain = $domainInputWithoutDot;

                        if (
                            $canonicalDomain !== ''
                            && $tldWithoutDot !== ''
                            && !str_ends_with(
                                $canonicalDomain,
                                '.' . $tldWithoutDot,
                            )
                        ) {
                            $canonicalDomain .=
                                '.' . $tldWithoutDot;
                        }
                    @endphp

                    @if ($canonicalDomain !== '')
                        <div class="mt-3 grid grid-cols-1 gap-3 rounded-lg border border-secondary/30 bg-secondary/10 px-4 py-3 sm:grid-cols-[1fr_auto] sm:items-center">
                            <div class="min-w-0">
                                <div class="text-xs font-semibold uppercase tracking-wide text-primary-100/60">
                                    Selected domain
                                </div>

                                <div class="break-all text-lg font-semibold text-primary-100">
                                    {{ $canonicalDomain }}
                                </div>
                            </div>

                            <div class="sm:text-right">
                                <div class="text-xs font-semibold uppercase tracking-wide text-primary-100/60">
                                    Current total
                                </div>

                                <div class="text-lg font-semibold text-primary-100">
                                    {{ $total }}
                                </div>
                            </div>
                        </div>
                    @endif
                @endif

                @if ($configOption->name === 'domain_action')
                    <div class="mt-1 text-sm text-primary-100/75">
                        @if (($checkoutConfig['domain_action'] ?? 'register') === 'transfer')
                            Transfer a domain that is already registered. You will need the domain's EPP or transfer code.
                        @else
                            Register a new domain that is currently available.
                        @endif
                    </div>
                @endif

                @if ($configOption->name === 'domain_sld')
                    <div class="-mt-2 rounded-b-lg border-x border-b border-neutral bg-background-secondary/40 px-4 pb-4 text-sm text-primary-100/75">
                        Enter either a name such as
                        <span class="font-medium text-primary-100">example</span>
                        or a complete domain such as
                        <span class="font-medium text-primary-100">example.co.za</span>.
                    </div>

                @endif

            @endif
        @endforeach
    </div>
    <div class="flex flex-col gap-3 w-full col-span-1 rounded-lg border border-neutral bg-background-secondary p-4 h-fit md:sticky md:top-6">
        <h2 class="text-2xl font-semibold  mb-2">
            {{ __('product.order_summary') }}
        </h2>
        @if ($total->total_tax > 0)
            <div class="font-semibold flex justify-between">
                <h4>{{ __('invoices.subtotal') }}:</h4> {{ $total->format($total->subtotal) }}
            </div>
            <div class="font-semibold flex justify-between">
                <h4>{{ \App\Classes\Settings::tax()->name }} ({{ \App\Classes\Settings::tax()->rate }}%):</h4> {{ $total->formatted->total_tax }}
            </div>
        @endif
        <div class="text-lg font-semibold flex justify-between">
            <h4>{{ __('product.total_today') }}:</h4> {{ $total }}
        </div>
        @if ($total->setup_fee > 0 && $plan->type == 'recurring')
            <div class="text- font-semibold flex justify-between ">
                <h4>{{ __('product.then_after_x', ['time' => $plan->billing_period . ' ' . trans_choice(__('services.billing_cycles.' . $plan->billing_unit), $plan->billing_period)]) }}:
                </h4> {{ $total->format($total->price) }}
            </div>
        @endif
        @if (($product->stock > 0 || !$product->stock) && $product->price()->available)
            <div
                wire:loading
                wire:target="checkout"
                class="rounded-md border border-secondary/40 bg-secondary/10 px-3 py-2 text-sm text-primary-100"
            >
                <div class="font-semibold">
                    Checking domain availability…
                </div>
                <div class="mt-1 text-primary-100/75">
                    Confirming the selected domain and preparing the final cart price.
                </div>
            </div>

            <div>
                <x-button.primary wire:click="checkout" wire:loading.attr="disabled">
                    <x-loading target="checkout" />
                    <div wire:loading.remove wire:target="checkout">
                        @if (($checkoutConfig['domain_action'] ?? 'register') === 'transfer')
                            Continue with domain transfer
                        @else
                            Check availability and continue
                        @endif
                    </div>
                </x-button.primary>
            </div>
        @endif
    </div>
</div>
