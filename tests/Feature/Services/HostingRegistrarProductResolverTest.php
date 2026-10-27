<?php

namespace Tests\Feature\Services;

use App\Models\Product;
use App\Models\Server;
use App\Services\HostingRegistrarProductResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class HostingRegistrarProductResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_only_products_using_an_enabled_registrar_server(): void
    {
        $enabledRegistrar = $this->server(
            'AbsoluteHostingDomains',
            true,
        );

        $disabledRegistrar = $this->server(
            'AbsoluteHostingDomains',
            false,
        );

        $hestia = $this->server('Hestia', true);

        $available = $this->product(
            'Available domain',
            $enabledRegistrar,
        );

        $this->product('Disabled domain', $disabledRegistrar);
        $this->product('Hosting product', $hestia);

        $this->assertSame(
            [$available->id => 'Available domain'],
            $this->resolver()->options(),
        );
    }

    public function test_it_resolves_the_configured_registrar_product(): void
    {
        $hostingProduct = $this->product(
            'Hosting',
            $this->server('Hestia', true),
        );

        $registrarProduct = $this->product(
            'Domain registration',
            $this->server('AbsoluteHostingDomains', true),
        );

        $this->configure(
            $hostingProduct,
            $registrarProduct->id,
        );

        $resolved = $this->resolver()->resolve($hostingProduct);

        $this->assertTrue($resolved->is($registrarProduct));
    }

    public function test_it_requires_a_configured_registrar_product(): void
    {
        $hostingProduct = $this->product(
            'Hosting',
            $this->server('Hestia', true),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'No registrar product is configured for this hosting product.',
        );

        $this->resolver()->resolve($hostingProduct);
    }

    public function test_it_rejects_a_missing_registrar_product(): void
    {
        $hostingProduct = $this->product(
            'Hosting',
            $this->server('Hestia', true),
        );

        $this->configure($hostingProduct, 999999);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The configured registrar product no longer exists.',
        );

        $this->resolver()->resolve($hostingProduct);
    }

    public function test_it_rejects_a_non_registrar_product(): void
    {
        $hostingProduct = $this->product(
            'Hosting',
            $this->server('Hestia', true),
        );

        $otherProduct = $this->product(
            'Other hosting',
            $this->server('Hestia', true),
        );

        $this->configure($hostingProduct, $otherProduct->id);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The configured product is not an Absolute Hosting registrar product.',
        );

        $this->resolver()->resolve($hostingProduct);
    }

    public function test_it_rejects_a_disabled_registrar_server(): void
    {
        $hostingProduct = $this->product(
            'Hosting',
            $this->server('Hestia', true),
        );

        $registrarProduct = $this->product(
            'Disabled registrar product',
            $this->server('AbsoluteHostingDomains', false),
        );

        $this->configure(
            $hostingProduct,
            $registrarProduct->id,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The configured registrar server is disabled.',
        );

        $this->resolver()->resolve($hostingProduct);
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

    private function configure(
        Product $hostingProduct,
        int $registrarProductId,
    ): void {
        $hostingProduct->settings()->create([
            'key' => HostingRegistrarProductResolver::SETTING_KEY,
            'type' => 'integer',
            'value' => $registrarProductId,
        ]);
    }

    private function resolver(): HostingRegistrarProductResolver
    {
        return new HostingRegistrarProductResolver;
    }
}
