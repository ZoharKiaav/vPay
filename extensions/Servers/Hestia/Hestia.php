<?php

namespace Paymenter\Extensions\Servers\Hestia;

use App\Classes\Extension\Server;
use App\Models\Service;
use App\Rules\Domain;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Hestia extends Server
{
    /**
     * Send a command to the Hestia API.
     *
     * Hestia access-key authentication uses:
     * ACCESS_KEY:SECRET_KEY
     */
    private function request(string $command, array $arguments = [], string $returnCode = 'json'): Response
    {
        $host = rtrim($this->config('host'), '/');
        $url = $host . '/api/';

        $payload = [
            'hash' => $this->config('access_key') . ':' . $this->config('secret_key'),
            'returncode' => $returnCode,
            'cmd' => $command,
        ];

        foreach (array_values($arguments) as $index => $value) {
            $payload['arg' . ($index + 1)] = $value;
        }

        $request = Http::asForm()
            ->accept('application/json')
            ->timeout((int) ($this->config('timeout') ?: 30));

        if (!$this->shouldVerifyTls()) {
            $request = $request->withoutVerifying();
        }

        $response = $request->post($url, $payload);

        if (!$response->successful()) {
            throw new Exception(
                'Hestia API HTTP error ' . $response->status() .
                ' while running ' . $command .
                ($response->body() ? ': ' . $response->body() : '')
            );
        }

        $exitCode = $response->header('Hestia-Exit-Code');

        if ($exitCode !== null && (string) $exitCode !== '0') {
            throw new Exception(
                'Hestia command failed with exit code ' . $exitCode .
                ' while running ' . $command .
                ($response->body() ? ': ' . $response->body() : '')
            );
        }

        return $response;
    }

    private function shouldVerifyTls(): bool
    {
        $value = $this->config('verify_tls');

        if ($value === null || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function panelUrl(): string
    {
        return rtrim($this->config('panel_url') ?: $this->config('host'), '/');
    }

    private function panelName(): string
    {
        return trim((string) ($this->config('panel_name') ?: 'vKloudStudio'));
    }

    private function configuredPackages(): array
    {
        $packages = trim((string) $this->config('packages'));

        if ($packages === '') {
            return [];
        }

        return collect(explode(',', $packages))
            ->map(fn ($package) => trim($package))
            ->filter()
            ->map(fn ($package) => [
                'label' => $package,
                'value' => $package,
            ])
            ->values()
            ->all();
    }

    private function generateUsername(Service $service, array $properties): string
    {
        $prefix = strtolower(
            preg_replace('/[^a-z0-9]/i', '', (string) ($this->config('username_prefix') ?: 'vks'))
        );

        if ($prefix === '' || is_numeric($prefix[0])) {
            $prefix = 'vks';
        }

        $domain = $properties['domain'] ?? '';
        $base = strtolower(
            preg_replace('/[^a-z0-9]/i', '', explode('.', $domain)[0] ?? '')
        );

        if (strlen($base) < 3) {
            $base = strtolower(
                preg_replace('/[^a-z0-9]/i', '', $service->user->name ?? '')
            );
        }

        if (strlen($base) < 3) {
            $base = 'client';
        }

        // Keep usernames short, readable, and low-risk for Hestia/Linux user creation.
        // Example: vksclient7x2a
        $random = Str::lower(Str::random(4));
        $maxLength = 16;
        $baseLength = max(3, $maxLength - strlen($prefix) - strlen($random));
        $base = substr($base, 0, $baseLength);

        return substr($prefix . $base . $random, 0, $maxLength);
    }

    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'host',
                'type' => 'text',
                'label' => 'Hestia URL',
                'placeholder' => 'https://panel.example.com:8083',
                'validation' => 'url:http,https',
                'required' => true,
            ],
            [
                'name' => 'access_key',
                'type' => 'text',
                'label' => 'Access key ID',
                'required' => true,
                'encrypted' => true,
            ],
            [
                'name' => 'secret_key',
                'type' => 'password',
                'label' => 'Secret access key',
                'required' => true,
                'encrypted' => true,
            ],
            [
                'name' => 'panel_url',
                'type' => 'text',
                'label' => 'Panel URL shown to clients',
                'placeholder' => 'https://panel.example.com:8083',
                'validation' => 'nullable|url:http,https',
                'required' => false,
                'description' => 'Leave empty to use the Hestia URL.',
            ],
            [
                'name' => 'panel_name',
                'type' => 'text',
                'label' => 'Panel name shown to clients',
                'default' => 'vKloudStudio',
                'placeholder' => 'vKloudStudio',
                'required' => false,
            ],
            [
                'name' => 'username_prefix',
                'type' => 'text',
                'label' => 'Generated username prefix',
                'default' => 'vks',
                'placeholder' => 'vks',
                'required' => false,
                'description' => 'Lowercase letters and numbers only. Used for new Hestia usernames, for example vksclient7x2a.',
            ],
            [
                'name' => 'packages',
                'type' => 'text',
                'label' => 'Available package names',
                'placeholder' => 'vLite,vNova,vTitan,vFreelance,vSpark,vCore,vSME,vEvolve,vScale',
                'required' => false,
                'description' => 'Comma-separated Hestia package names. These must already exist in Hestia and must match exactly.',
            ],
            [
                'name' => 'verify_tls',
                'type' => 'checkbox',
                'label' => 'Verify TLS certificate',
                'default' => true,
                'required' => false,
            ],
            [
                'name' => 'timeout',
                'type' => 'number',
                'label' => 'API timeout',
                'default' => 30,
                'required' => false,
                'min_value' => 5,
                'suffix' => 'seconds',
            ],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $this->request('v-list-users', ['json']);

            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    public function getProductConfig($values = []): array
    {
        $packages = $this->configuredPackages();

        $packageField = [
            'name' => 'package',
            'label' => 'Hestia package',
            'required' => true,
            'description' => 'The package must already exist in Hestia and the name must match exactly.',
        ];

        if (count($packages) > 0) {
            $packageField['type'] = 'select';
            $packageField['options'] = $packages;
        } else {
            $packageField['type'] = 'text';
            $packageField['placeholder'] = 'vLite';
        }

        return [
            $packageField,
            [
                'name' => 'create_domain',
                'type' => 'checkbox',
                'label' => 'Create main domain in Hestia',
                'default' => true,
                'required' => false,
            ],
        ];
    }

    public function getCheckoutConfig()
    {
        return [
            [
                'name' => 'domain',
                'type' => 'text',
                'label' => 'Domain',
                'required' => true,
                'validation' => [new Domain, 'required'],
                'placeholder' => 'example.com',
            ],
        ];
    }

    public function createServer(Service $service, $settings, $properties)
    {
        if (isset($properties['hestia_username'])) {
            throw new Exception('Service has already been created in Hestia');
        }

        $settings = array_merge($settings, $properties);

        if (empty($settings['package'])) {
            throw new Exception('No Hestia package configured for this product');
        }

        if (empty($properties['domain'])) {
            throw new Exception('No domain was provided for this service');
        }

        $username = $this->generateUsername($service, $properties);
        $password = Str::password(16);
        $package = $settings['package'];
        $domain = strtolower($properties['domain']);

        $nameParts = preg_split('/\s+/', trim($service->user->name ?? ''), 2);
        $firstName = $nameParts[0] ?? 'Client';
        $lastName = $nameParts[1] ?? 'User';

        $this->request('v-add-user', [
            $username,
            $password,
            $service->user->email,
            $package,
            $firstName,
            $lastName,
        ]);

        $createDomain = filter_var($settings['create_domain'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if ($createDomain) {
            $this->request('v-add-domain', [
                $username,
                $domain,
            ]);
        }

        $service->properties()->updateOrCreate([
            'key' => 'hestia_username',
        ], [
            'name' => $this->panelName() . ' username',
            'value' => $username,
        ]);

        $service->properties()->updateOrCreate([
            'key' => 'hestia_password',
        ], [
            'name' => 'Initial ' . $this->panelName() . ' password',
            'value' => $password,
        ]);

        $service->properties()->updateOrCreate([
            'key' => 'hestia_domain',
        ], [
            'name' => 'Domain',
            'value' => $domain,
        ]);

        $service->properties()->updateOrCreate([
            'key' => 'hestia_package',
        ], [
            'name' => 'Hestia package',
            'value' => $package,
        ]);

        $service->properties()->updateOrCreate([
            'key' => 'hestia_panel_url',
        ], [
            'name' => $this->panelName() . ' panel URL',
            'value' => $this->panelUrl(),
        ]);

        return [
            'username' => $username,
            'password' => $password,
            'domain' => $domain,
            'package' => $package,
            'panel_url' => $this->panelUrl(),
        ];
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        if (!isset($properties['hestia_username'])) {
            throw new Exception('Service has not been created in Hestia');
        }

        $this->request('v-suspend-user', [$properties['hestia_username']]);

        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        if (!isset($properties['hestia_username'])) {
            throw new Exception('Service has not been created in Hestia');
        }

        $this->request('v-unsuspend-user', [$properties['hestia_username']]);

        return true;
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        if (!isset($properties['hestia_username'])) {
            throw new Exception('Service has not been created in Hestia');
        }

        $this->request('v-delete-user', [$properties['hestia_username']]);

        return true;
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        if (!isset($properties['hestia_username'])) {
            throw new Exception('Service has not been created in Hestia');
        }

        $settings = array_merge($settings, $properties);

        if (empty($settings['package'])) {
            throw new Exception('No Hestia package configured for this product');
        }

        $this->request('v-change-user-package', [
            $properties['hestia_username'],
            $settings['package'],
        ]);

        $service->properties()->updateOrCreate([
            'key' => 'hestia_package',
        ], [
            'name' => 'Hestia package',
            'value' => $settings['package'],
        ]);

        return true;
    }

    public function getLoginUrl(Service $service, $settings, $properties): string
    {
        return $properties['hestia_panel_url'] ?? $this->panelUrl();
    }

    public function getActions(Service $service, $settings, $properties): array
    {
        $actions = [];

        if (isset($properties['hestia_username'])) {
            $actions[] = [
                'label' => $this->panelName() . ' username',
                'text' => $properties['hestia_username'],
                'type' => 'text',
            ];
        }

        if (isset($properties['hestia_password'])) {
            $actions[] = [
                'label' => 'Initial ' . $this->panelName() . ' password',
                'text' => $properties['hestia_password'],
                'type' => 'text',
            ];
        }

        if (isset($properties['hestia_domain'])) {
            $actions[] = [
                'label' => 'Domain',
                'text' => $properties['hestia_domain'],
                'type' => 'text',
            ];
        }

        if (isset($properties['hestia_package'])) {
            $actions[] = [
                'label' => 'Package',
                'text' => $properties['hestia_package'],
                'type' => 'text',
            ];
        }

        $actions[] = [
            'name' => 'hestia_panel',
            'label' => 'Open ' . $this->panelName(),
            'url' => $properties['hestia_panel_url'] ?? $this->panelUrl(),
            'type' => 'button',
        ];

        return $actions;
    }
}