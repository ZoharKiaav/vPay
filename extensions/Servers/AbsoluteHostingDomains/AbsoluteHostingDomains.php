<?php

namespace Paymenter\Extensions\Servers\AbsoluteHostingDomains;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Models\Product;
use App\Models\Service;
use Closure;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingClient;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Models\ManagedDomain;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\CustomerPropertyExtractor;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainCatalogService;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainNameNormalizer;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainOrderContextFactory;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainOrderPayloadFactory;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainOrderService;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainRetailPriceService;

#[ExtensionMeta(
    name: 'Absolute Hosting Domains',
    description: 'Domain checkout integration for the Absolute Hosting reseller API',
    version: '0.1.0',
    author: 'vKloud',
)]
class AbsoluteHostingDomains extends Server
{
    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'api_endpoint',
                'label' => 'API Endpoint',
                'type' => 'text',
                'description' => 'Absolute Hosting Domains Reseller REST API endpoint.',
                'required' => true,
                'default' => 'https://client.absolutehosting.co.za/modules/addons/DomainsReseller/api/index.php',
                'validation' => 'required|url',
            ],
            [
                'name' => 'api_username',
                'label' => 'API Email Address',
                'type' => 'text',
                'description' => 'API email address provided in the Domains Reseller configuration.',
                'required' => true,
                'encrypted' => true,
                'validation' => 'required|email',
            ],
            [
                'name' => 'api_key',
                'label' => 'API Key',
                'type' => 'password',
                'description' => 'API key provided in the Domains Reseller configuration.',
                'required' => true,
                'encrypted' => true,
                'validation' => 'required|string',
            ],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $this->catalog()->tlds();

            return true;
        } catch (AbsoluteHostingException $exception) {
            return $exception->getMessage();
        }
    }

    public function getProductConfig($values = []): array
    {
        return [
            [
                'name' => 'markup_percentage',
                'type' => 'number',
                'label' => 'Domain markup percentage',
                'default' => 0,
                'required' => true,
                'min_value' => 0,
                'description' => 'Percentage added to the provider price.',
            ],
            [
                'name' => 'markup_fixed',
                'type' => 'number',
                'label' => 'Fixed domain markup',
                'default' => 0,
                'required' => true,
                'min_value' => 0,
                'description' => 'Fixed amount added per registration year.',
            ],
            [
                'name' => 'default_nameserver_1',
                'type' => 'text',
                'label' => 'Default primary nameserver',
                'default' => 'red.proniit.com',
                'required' => true,
            ],
            [
                'name' => 'default_nameserver_2',
                'type' => 'text',
                'label' => 'Default secondary nameserver',
                'default' => 'yellow.proniit.com',
                'required' => true,
            ],
            [
                'name' => 'registration_enabled',
                'type' => 'checkbox',
                'label' => 'Allow domain registration',
                'default' => true,
                'required' => false,
            ],
            [
                'name' => 'transfer_enabled',
                'type' => 'checkbox',
                'label' => 'Allow domain transfer',
                'default' => true,
                'required' => false,
            ],
            [
                'name' => 'allow_custom_nameservers',
                'type' => 'checkbox',
                'label' => 'Allow custom nameservers',
                'default' => false,
                'required' => false,
                'description' => 'Allow customers to override the default nameservers during checkout.',
            ],
        ];
    }

    public function getCheckoutConfig(
        Product $product,
        array $values = [],
        array $settings = [],
    ): array {
        $actionOptions = [];

        if ($this->settingEnabled(
            $settings,
            'registration_enabled',
            true,
        )) {
            $actionOptions['register'] = 'Register a new domain';
        }

        if ($this->settingEnabled(
            $settings,
            'transfer_enabled',
            true,
        )) {
            $actionOptions['transfer'] = 'Transfer an existing domain';
        }

        if ($actionOptions === []) {
            throw new \LogicException(
                'Domain registration and transfer are both disabled for this product.',
            );
        }

        $action = $this->normaliseAction(
            (string) (
                $values['domain_action']
                    ?? array_key_first($actionOptions)
            ),
            array_keys($actionOptions),
        );

        $allowCustomNameservers = $this->settingEnabled(
            $settings,
            'allow_custom_nameservers',
            false,
        );

        $useCustomNameservers = $allowCustomNameservers
            && filter_var(
                $values['use_custom_nameservers'] ?? false,
                FILTER_VALIDATE_BOOL,
            );

        $tldOptions = $this->tldOptions($settings);
        $selectedTld = $this->normaliseSelectedTld(
            (string) ($values['domain_tld']
                ?? array_key_first($tldOptions)
                ?? ''),
            $tldOptions,
        );

        $periodOptions = $this->periodOptions($selectedTld);
        $selectedPeriod = (string) (
            $values['registration_period']
                ?? array_key_first($periodOptions)
                ?? '1'
        );

        $defaultNameserver1 = trim((string) (
            $settings['default_nameserver_1']
                ?? 'red.proniit.com'
        ));

        $defaultNameserver2 = trim((string) (
            $settings['default_nameserver_2']
                ?? 'yellow.proniit.com'
        ));

        return [
            [
                'name' => 'domain_action',
                'type' => 'radio',
                'label' => 'Domain action',
                'required' => true,
                'default' => $action,
                'options' => $actionOptions,
            ],
            [
                'name' => 'domain_sld',
                'type' => 'text',
                'label' => 'Domain name',
                'required' => true,
                'placeholder' => 'example',
                'validation' => [
                    'required',
                    'string',
                    'max:253',
                    $this->domainEligibilityRule(
                        $action,
                        $selectedTld,
                    ),
                ],
            ],
            [
                'name' => 'domain_tld',
                'type' => 'select',
                'label' => 'Domain extension',
                'required' => true,
                'default' => $selectedTld,
                'options' => $tldOptions,
            ],
            [
                'name' => 'registration_period',
                'type' => 'select',
                'label' => 'Registration period',
                'required' => true,
                'default' => $selectedPeriod,
                'options' => $periodOptions,
            ],
            [
                'name' => 'use_custom_nameservers',
                'type' => 'checkbox',
                'label' => 'Use custom nameservers',
                'required' => false,
                'default' => $useCustomNameservers,
                'visible' => $allowCustomNameservers,
            ],
            [
                'name' => 'nameserver_1',
                'type' => 'text',
                'label' => 'Primary nameserver',
                'required' => true,
                'placeholder' => 'ns1.example.com',
                'validation' => $this->nameserverValidation(),
                'default' => $useCustomNameservers
                    ? ($values['nameserver_1'] ?? $defaultNameserver1)
                    : $defaultNameserver1,
                'visible' => $useCustomNameservers,
            ],
            [
                'name' => 'nameserver_2',
                'type' => 'text',
                'label' => 'Secondary nameserver',
                'required' => true,
                'placeholder' => 'ns2.example.com',
                'validation' => $this->nameserverValidation(),
                'default' => $useCustomNameservers
                    ? ($values['nameserver_2'] ?? $defaultNameserver2)
                    : $defaultNameserver2,
                'visible' => $useCustomNameservers,
            ],
            [
                'name' => 'nameserver_3',
                'type' => 'text',
                'label' => 'Nameserver 3',
                'required' => false,
                'placeholder' => 'ns3.example.com',
                'validation' => $this->optionalNameserverValidation(),
                'visible' => $useCustomNameservers,
            ],
            [
                'name' => 'nameserver_4',
                'type' => 'text',
                'label' => 'Nameserver 4',
                'required' => false,
                'placeholder' => 'ns4.example.com',
                'validation' => $this->optionalNameserverValidation(),
                'visible' => $useCustomNameservers,
            ],
            [
                'name' => 'nameserver_5',
                'type' => 'text',
                'label' => 'Nameserver 5',
                'required' => false,
                'placeholder' => 'ns5.example.com',
                'validation' => $this->optionalNameserverValidation(),
                'visible' => $useCustomNameservers,
            ],
            [
                'name' => 'epp_code',
                'type' => 'text',
                'label' => 'Transfer authorisation code',
                'required' => $action === 'transfer',
                'validation' => $action === 'transfer'
                    ? 'required|string|max:255'
                    : 'nullable|string|max:255',
                'visible' => $action === 'transfer',
            ],
        ];
    }

    public function createServer(
        Service $service,
        $settings,
        $properties,
    ): array {
        $service->loadMissing('user.properties');

        $context = $this->prepareDomainOrder(
            $service,
            (array) $properties,
        );

        $managedDomain = ManagedDomain::query()->firstOrNew([
            'service_id' => $service->getKey(),
        ]);

        return $this->orderService()->submit(
            managedDomain: $managedDomain,
            action: $context['action'],
            serviceProperties: $context['service_properties'],
            customer: $context['customer'],
            customerProperties: $context['customer_properties'],
        );
    }

    public function prepareDomainOrder(
        Service $service,
        array $properties,
    ): array {
        $properties = $this->normaliseDomainValues($properties);

        return $this->contextFactory()->make(
            $service,
            $properties,
        );
    }

    public function normaliseDomainValues(array $values): array
    {
        if (!array_key_exists('domain_sld', $values)) {
            return $values;
        }

        $input = trim((string) $values['domain_sld']);
        $selectedTld = (string) ($values['domain_tld'] ?? '');

        $requiresCatalog = str_contains($input, '.')
            || str_contains($input, '://');

        $availableTlds = $requiresCatalog
            ? array_keys($this->catalog()->pricing())
            : [$selectedTld];

        $normalised = $this->domainNameNormalizer()->normalise(
            $input,
            $availableTlds,
            $selectedTld,
        );

        $values['domain_sld'] = $normalised['sld'];
        $values['domain_tld'] = $normalised['tld'];

        return $values;
    }

    public function synchroniseCheckoutConfig(
        array $values,
        string $changedKey,
        array $settings = [],
    ): array {
        if ($changedKey !== 'domain_sld') {
            return $values;
        }

        $input = trim((string) (
            $values['domain_sld'] ?? ''
        ));

        if (
            $input === ''
            || (
                !str_contains($input, '.')
                && !str_contains($input, '://')
            )
        ) {
            return $values;
        }

        $normalised = $this->normaliseDomainValues($values);

        $values['domain_sld'] = $normalised['domain_sld'];
        $values['domain_tld'] = $normalised['domain_tld'];

        $periodOptions = $this->periodOptions(
            $values['domain_tld'],
        );

        $selectedPeriod = (string) (
            $values['registration_period'] ?? ''
        );

        if (!array_key_exists($selectedPeriod, $periodOptions)) {
            $values['registration_period'] =
                (string) array_key_first($periodOptions);
        }

        return $values;
    }

    public function prepareCheckoutConfigForCart(
        array $values,
        array $settings = [],
    ): array {
        $values = $this->normaliseDomainValues($values);

        $allowedActions = [];

        if ($this->settingEnabled(
            $settings,
            'registration_enabled',
            true,
        )) {
            $allowedActions[] = 'register';
        }

        if ($this->settingEnabled(
            $settings,
            'transfer_enabled',
            true,
        )) {
            $allowedActions[] = 'transfer';
        }

        if ($allowedActions === []) {
            throw new \LogicException(
                'Domain registration and transfer are both disabled for this product.',
            );
        }

        $action = $this->normaliseAction(
            (string) ($values['domain_action'] ?? ''),
            $allowedActions,
        );

        $values['domain_action'] = $action;

        $useCustomNameservers = $this->settingEnabled(
            $settings,
            'allow_custom_nameservers',
            false,
        ) && filter_var(
            $values['use_custom_nameservers'] ?? false,
            FILTER_VALIDATE_BOOL,
        );

        $values['use_custom_nameservers'] = $useCustomNameservers;

        if (!$useCustomNameservers) {
            $values['nameserver_1'] = trim((string) (
                $settings['default_nameserver_1']
                    ?? 'red.proniit.com'
            ));

            $values['nameserver_2'] = trim((string) (
                $settings['default_nameserver_2']
                    ?? 'yellow.proniit.com'
            ));

            foreach ([
                'nameserver_3',
                'nameserver_4',
                'nameserver_5',
            ] as $nameserver) {
                $values[$nameserver] = '';
            }
        } else {
            foreach ([
                'nameserver_1',
                'nameserver_2',
                'nameserver_3',
                'nameserver_4',
                'nameserver_5',
            ] as $nameserver) {
                $values[$nameserver] = trim((string) (
                    $values[$nameserver] ?? ''
                ));
            }
        }

        if ($action !== 'transfer') {
            $values['epp_code'] = '';
        } else {
            $values['epp_code'] = trim((string) (
                $values['epp_code'] ?? ''
            ));
        }

        $this->assertDomainEligible(
            $action,
            (string) ($values['domain_sld'] ?? ''),
            (string) ($values['domain_tld'] ?? ''),
        );

        return $values;
    }

    public function contextFactory(): DomainOrderContextFactory
    {
        return new DomainOrderContextFactory(
            new CustomerPropertyExtractor,
        );
    }

    public function orderService(): DomainOrderService
    {
        return new DomainOrderService(
            $this->client(),
            new DomainOrderPayloadFactory,
        );
    }

    public function client(): AbsoluteHostingClient
    {
        return new AbsoluteHostingClient(
            endpoint: (string) $this->config('api_endpoint'),
            username: (string) $this->config('api_username'),
            apiKey: (string) $this->config('api_key'),
        );
    }

    public function catalog(): DomainCatalogService
    {
        return new DomainCatalogService($this->client());
    }

    public function checkoutPrice(
        array $values,
        array $settings = [],
    ): float {
        if (trim((string) ($values['domain_sld'] ?? '')) === '') {
            return 0.0;
        }

        $values = $this->normaliseDomainValues($values);

        $action = $this->normaliseAction(
            (string) ($values['domain_action'] ?? 'register'),
        );

        $tld = '.' . strtolower(ltrim(
            trim((string) ($values['domain_tld'] ?? '')),
            '.',
        ));

        $period = (string) ($values['registration_period'] ?? '1');
        $pricing = $this->catalog()->pricingFor($tld);

        if ($pricing === null) {
            throw new AbsoluteHostingException(
                "Absolute Hosting returned no pricing for {$tld}.",
            );
        }

        $providerPrice = $action === 'transfer'
            ? $pricing['transfer_price']
            : $pricing['registration_price'];

        return $this->retailPriceService()->calculate(
            providerPrice: $providerPrice,
            period: $period,
            settings: $settings,
        );
    }

    private function tldOptions(array $settings = []): array
    {
        $pricing = $this->catalog()->pricing();
        $options = [];

        foreach ($pricing as $tld => $record) {
            if (!$record['registration_available']) {
                continue;
            }

            $registrationPrice = $this->retailPriceService()->calculate(
                providerPrice: $record['registration_price'],
                period: 1,
                settings: $settings,
            );
            $currencyCode = $record['currency_code'];

            $options[$tld] = sprintf(
                '%s (%s %s)',
                $tld,
                $currencyCode,
                $registrationPrice,
            );
        }

        if ($options === []) {
            throw new AbsoluteHostingException(
                'Absolute Hosting returned no registerable TLDs.',
            );
        }

        return $options;
    }

    private function periodOptions(string $tld): array
    {
        $pricing = $this->catalog()->pricingFor($tld);

        if ($pricing === null) {
            throw new AbsoluteHostingException(
                "Absolute Hosting returned no pricing for {$tld}.",
            );
        }

        $options = [];

        foreach ($pricing['years'] as $year) {
            $label = $year === 1 ? '1 year' : "{$year} years";
            $options[(string) $year] = $label;
        }

        if ($options === []) {
            throw new AbsoluteHostingException(
                "Absolute Hosting returned no registration periods for {$tld}.",
            );
        }

        return $options;
    }

    private function domainEligibilityRule(
        string $action,
        string $tld,
    ): Closure {
        return function (
            string $attribute,
            mixed $value,
            Closure $fail,
        ) use ($action, $tld): void {
            try {
                $normalised = $this->domainNameNormalizer()->normalise(
                    (string) $value,
                    [$tld],
                    $tld,
                );

                $this->assertDomainEligible(
                    $action,
                    $normalised['sld'],
                    $normalised['tld'],
                );
            } catch (AbsoluteHostingException $exception) {
                report($exception);
                $fail($exception->customerMessage);
            } catch (\InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        };
    }

    private function assertDomainEligible(
        string $action,
        string $sld,
        string $tld,
    ): void {
        $results = $this->catalog()->availability(
            $sld,
            [$tld],
        );

        $result = $results[0] ?? null;

        if ($result === null || !$result['is_valid']) {
            throw new \InvalidArgumentException(
                'The selected domain name is invalid.',
            );
        }

        if (
            $action === 'register'
            && (
                $result['is_registered']
                || !$result['is_available']
            )
        ) {
            throw new \InvalidArgumentException(
                'The selected domain is not available for registration.',
            );
        }

        if (
            $action === 'transfer'
            && !$result['is_registered']
        ) {
            throw new \InvalidArgumentException(
                'The selected domain is not registered and cannot be transferred.',
            );
        }
    }

    private function domainNameNormalizer(): DomainNameNormalizer
    {
        return new DomainNameNormalizer;
    }

    private function retailPriceService(): DomainRetailPriceService
    {
        return new DomainRetailPriceService;
    }

    private function nameserverValidation(): array
    {
        return [
            'required',
            'string',
            'max:253',
            'regex:/^(?=.{1,253}\.?$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.?$/i',
        ];
    }

    private function optionalNameserverValidation(): array
    {
        return [
            'nullable',
            'string',
            'max:253',
            'regex:/^(?=.{1,253}\.?$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.?$/i',
        ];
    }

    private function normaliseAction(
        string $action,
        array $allowedActions = ['register', 'transfer'],
    ): string {
        $action = strtolower(trim($action));

        return in_array($action, $allowedActions, true)
            ? $action
            : (string) reset($allowedActions);
    }

    private function settingEnabled(
        array $settings,
        string $key,
        bool $default,
    ): bool {
        if (!array_key_exists($key, $settings)) {
            return $default;
        }

        return filter_var(
            $settings[$key],
            FILTER_VALIDATE_BOOL,
        );
    }

    private function normaliseSelectedTld(
        string $tld,
        array $options,
    ): string {
        $normalised = '.' . strtolower(ltrim(trim($tld), '.'));

        if (array_key_exists($normalised, $options)) {
            return $normalised;
        }

        return (string) array_key_first($options);
    }
}
