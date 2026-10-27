<?php

namespace App\Services;

use App\Helpers\ExtensionHelper;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Support\Str;
use LogicException;

class HostingDomainCartPayloadBuilder
{
    public const LINK_ID_KEY = 'hosting_domain_link_id';

    public const LINK_ROLE_KEY = 'hosting_domain_link_role';

    public const ROLE_HOSTING = 'hosting';

    public const ROLE_REGISTRAR = 'registrar';

    public function __construct(
        private readonly HostingDomainCheckoutService $checkoutService,
        private readonly HostingRegistrarProductResolver $resolver,
    ) {}

    public function build(
        Product $hostingProduct,
        Plan $hostingPlan,
        array $hostingConfigOptions,
        array $customerValues,
        ?string $linkId = null,
    ): HostingDomainCartPayloads {
        $selection = $this->checkoutService->prepare(
            $hostingProduct,
            $customerValues,
        );

        $linkId = $this->normaliseLinkId($linkId);

        $hostingCheckoutConfig = array_merge(
            $selection->toCheckoutConfig(),
            [
                self::LINK_ID_KEY => $linkId,
                self::LINK_ROLE_KEY => self::ROLE_HOSTING,
            ],
        );

        $hostingPayload = [
            'product' => $hostingProduct,
            'plan' => $hostingPlan,
            'config_options' => $hostingConfigOptions,
            'checkout_config' => $hostingCheckoutConfig,
            'quantity' => 1,
        ];

        if (!$selection->requiresRegistrarService()) {
            return new HostingDomainCartPayloads(
                hosting: $hostingPayload,
                registrar: null,
                linkId: $linkId,
            );
        }

        $registrarProduct = $this->resolver->resolve(
            $hostingProduct,
        );

        $registrarPlan = $this->resolveRegistrarPlan(
            $registrarProduct,
        );

        $registrarCheckoutConfig = [
            'domain_action' => $selection->registrarAction,
            'domain_sld' => $selection->sld,
            'domain_tld' => $selection->tld,
            'registration_period' => (string) (
                $customerValues['registration_period'] ?? '1'
            ),
            'use_custom_nameservers' => $customerValues[
                'use_custom_nameservers'
            ] ?? false,
            'nameserver_1' => $customerValues[
                'nameserver_1'
            ] ?? null,
            'nameserver_2' => $customerValues[
                'nameserver_2'
            ] ?? null,
            'nameserver_3' => $customerValues[
                'nameserver_3'
            ] ?? null,
            'nameserver_4' => $customerValues[
                'nameserver_4'
            ] ?? null,
            'nameserver_5' => $customerValues[
                'nameserver_5'
            ] ?? null,
            'epp_code' => $selection->eppCode,
        ];

        $registrarCheckoutConfig =
            ExtensionHelper::prepareCheckoutConfig(
                $registrarProduct,
                $registrarCheckoutConfig,
            );

        $registrarCheckoutConfig[self::LINK_ID_KEY] = $linkId;
        $registrarCheckoutConfig[self::LINK_ROLE_KEY] =
            self::ROLE_REGISTRAR;

        return new HostingDomainCartPayloads(
            hosting: $hostingPayload,
            registrar: [
                'product' => $registrarProduct,
                'plan' => $registrarPlan,
                'config_options' => [],
                'checkout_config' => $registrarCheckoutConfig,
                'quantity' => 1,
            ],
            linkId: $linkId,
        );
    }

    private function resolveRegistrarPlan(
        Product $registrarProduct,
    ): Plan {
        $currency = session(
            'currency',
            config('settings.default_currency'),
        );

        $plan = $registrarProduct
            ->availablePlans($currency)
            ->sortBy([
                ['sort', 'asc'],
                ['id', 'asc'],
            ])
            ->first();

        if ($plan === null) {
            throw new LogicException(
                'The configured registrar product has no available plan in the selected currency.',
            );
        }

        if (!$plan->price($currency)->available) {
            throw new LogicException(
                'The configured registrar plan has no available price in the selected currency.',
            );
        }

        return $plan;
    }

    private function normaliseLinkId(
        ?string $linkId,
    ): string {
        $linkId = trim((string) $linkId);

        if ($linkId === '') {
            return (string) Str::uuid();
        }

        if (!Str::isUuid($linkId)) {
            throw new LogicException(
                'The hosting-domain cart link identifier is invalid.',
            );
        }

        return $linkId;
    }
}
