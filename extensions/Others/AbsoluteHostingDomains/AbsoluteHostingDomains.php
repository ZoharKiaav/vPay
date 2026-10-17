<?php

namespace Paymenter\Extensions\Others\AbsoluteHostingDomains;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingClient;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Models\ManagedDomain;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainCatalogService;

#[ExtensionMeta(
    name: 'Absolute Hosting Domains',
    description: 'Domain reseller integration for the Absolute Hosting REST API',
    version: '0.2.0',
    author: 'vKloud',
)]
class AbsoluteHostingDomains extends Extension
{
    public function __construct(public $config = []) {}

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

    public function installed(): void
    {
        ExtensionHelper::runMigrations(
            'extensions/Others/AbsoluteHostingDomains/database/migrations'
        );
    }

    public function uninstalled(): void
    {
        ExtensionHelper::rollbackMigrations(
            'extensions/Others/AbsoluteHostingDomains/database/migrations'
        );
    }

    public function boot(): void
    {
        Service::resolveRelationUsing(
            'managedDomain',
            function (Service $service) {
                return $service->hasOne(
                    ManagedDomain::class,
                    'service_id',
                    'id',
                );
            },
        );
    }

    public function catalog(): DomainCatalogService
    {
        return new DomainCatalogService($this->client());
    }

    public function client(): AbsoluteHostingClient
    {
        return new AbsoluteHostingClient(
            endpoint: (string) $this->config('api_endpoint'),
            username: (string) $this->config('api_username'),
            apiKey: (string) $this->config('api_key'),
        );
    }
}
