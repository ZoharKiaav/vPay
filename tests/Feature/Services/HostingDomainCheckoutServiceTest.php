<?php

namespace Tests\Feature\Services;

use App\Models\Product;
use App\Models\Server;
use App\Services\HostingDomainCheckoutService;
use App\Services\HostingDomainSelection;
use App\Services\HostingDomainSelectionService;
use App\Services\HostingRegistrarProductResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class HostingDomainCheckoutServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_uses_the_configured_registrar_product(): void
    {
        [$hostingProduct, $registrarProduct] =
            $this->configuredProducts();

        $selection = $this->service()->prepare(
            $hostingProduct,
            [
                'domain_mode' => 'register',
                'domain_sld' => ' Example ',
                'domain_tld' => '.co.za',
                'registrar_product_id' => 999999,
            ],
        );

        $this->assertSame(
            HostingDomainSelection::MODE_REGISTER,
            $selection->mode,
        );

        $this->assertSame(
            $registrarProduct->id,
            $selection->registrarProductId,
        );

        $this->assertSame('example.co.za', $selection->domain);
        $this->assertNull($selection->eppCode);
    }

    public function test_transfer_uses_the_configured_registrar_product(): void
    {
        [$hostingProduct, $registrarProduct] =
            $this->configuredProducts();

        $selection = $this->service()->prepare(
            $hostingProduct,
            [
                'domain_mode' => 'transfer',
                'domain_sld' => 'example',
                'domain_tld' => '.co.za',
                'epp_code' => ' TEST-CODE ',
                'registrar_product_id' => 999999,
            ],
        );

        $this->assertSame(
            HostingDomainSelection::MODE_TRANSFER,
            $selection->mode,
        );

        $this->assertSame(
            $registrarProduct->id,
            $selection->registrarProductId,
        );

        $this->assertSame('TEST-CODE', $selection->eppCode);
    }

    public function test_external_mode_does_not_require_a_registrar_product(): void
    {
        $hostingProduct = $this->product(
            'Hosting',
            $this->server('Hestia', true),
        );

        $selection = $this->service()->prepare(
            $hostingProduct,
            [
                'domain_mode' => 'external',
                'domain' => ' Existing.Example.COM. ',
                'registrar_product_id' => 999999,
            ],
        );

        $this->assertSame(
            HostingDomainSelection::MODE_EXTERNAL,
            $selection->mode,
        );

        $this->assertSame(
            'existing.example.com',
            $selection->domain,
        );

        $this->assertNull($selection->registrarProductId);
    }

    public function test_managed_mode_rejects_an_unconfigured_hosting_product(): void
    {
        $hostingProduct = $this->product(
            'Hosting',
            $this->server('Hestia', true),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'No registrar product is configured for this hosting product.',
        );

        $this->service()->prepare(
            $hostingProduct,
            [
                'domain_mode' => 'register',
                'domain_sld' => 'example',
                'domain_tld' => '.co.za',
            ],
        );
    }

    public function test_prepared_config_contains_only_canonical_values(): void
    {
        [$hostingProduct, $registrarProduct] =
            $this->configuredProducts();

        $config = $this->service()->prepareConfig(
            $hostingProduct,
            [
                'domain_mode' => 'register',
                'domain_sld' => 'Example',
                'domain_tld' => '.co.za',
                'registrar_product_id' => 999999,
                'untrusted_field' => 'discard me',
            ],
        );

        $this->assertSame([
            'domain_mode' => 'register',
            'domain' => 'example.co.za',
            'domain_action' => 'register',
            'domain_sld' => 'example',
            'domain_tld' => '.co.za',
            'epp_code' => null,
            'registrar_product_id' => $registrarProduct->id,
        ], $config);

        $this->assertArrayNotHasKey(
            'untrusted_field',
            $config,
        );
    }

    private function configuredProducts(): array
    {
        $hostingProduct = $this->product(
            'Hosting',
            $this->server('Hestia', true),
        );

        $registrarProduct = $this->product(
            'Domain registration',
            $this->server('AbsoluteHostingDomains', true),
        );

        $hostingProduct->settings()->create([
            'key' => HostingRegistrarProductResolver::SETTING_KEY,
            'type' => 'integer',
            'value' => $registrarProduct->id,
        ]);

        return [$hostingProduct, $registrarProduct];
    }

    private function server(
        string $extension,
        bool $enabled,
    ): Server {
        return Server::query()->create([
            'name' => $extension . ' test server',
            'extension' => $extension,
            'type' => 'server',
            'enabled' => $enabled,
        ]);
    }

    private function product(
        string $name,
        Server $server,
    ): Product {
        return Product::factory()->create([
            'name' => $name,
            'server_id' => $server->id,
        ]);
    }

    private function service(): HostingDomainCheckoutService
    {
        return new HostingDomainCheckoutService(
            new HostingRegistrarProductResolver,
            new HostingDomainSelectionService,
        );
    }
}
