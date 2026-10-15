<?php

namespace Paymenter\Extensions\Others\AbsoluteHostingDomains;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Client\AbsoluteHostingClient;

#[ExtensionMeta(
    name: 'Absolute Hosting Domains',
    description: 'Domain reseller integration for the Absolute Hosting REST API',
    version: '0.1.0',
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

    public function client(): AbsoluteHostingClient
    {
        return new AbsoluteHostingClient(
            endpoint: (string) $this->config('api_endpoint'),
            username: (string) $this->config('api_username'),
            apiKey: (string) $this->config('api_key'),
        );
    }
}
