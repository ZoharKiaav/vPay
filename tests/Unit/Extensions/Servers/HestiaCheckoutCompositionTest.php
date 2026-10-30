<?php

namespace Tests\Unit\Extensions\Servers;

use App\Services\HostingDomainSelection;
use Paymenter\Extensions\Servers\Hestia\Hestia;
use Tests\TestCase;

class HestiaCheckoutCompositionTest extends TestCase
{
    public function test_external_mode_exposes_only_existing_domain(): void
    {
        $config = (new Hestia)->getCheckoutConfig(
            new \App\Models\Product,
            ['domain_mode' => HostingDomainSelection::MODE_EXTERNAL],
        );

        $this->assertSame(
            ['domain_mode', 'domain'],
            array_column($config, 'name'),
        );
        $this->assertSame('text', $config[1]['type']);
        $this->assertTrue($config[1]['required']);
    }

    public function test_external_mode_has_no_domain_charge(): void
    {
        $price = (new Hestia)->checkoutPrice(
            ['domain_mode' => HostingDomainSelection::MODE_EXTERNAL],
            [],
        );

        $this->assertSame(0.0, $price);
    }

    public function test_linked_registrar_edit_uses_hosting_item(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4)
            . '/themes/default/views/cart.blade.php',
        );

        $this->assertIsString($source);

        $this->assertStringContainsString(
            '$linkedRole === \'registrar\'',
            $source,
        );

        $this->assertStringContainsString(
            "'hosting_domain_link_id'",
            $source,
        );

        $this->assertStringContainsString(
            "'hosting_domain_link_role'",
            $source,
        );

        $this->assertStringContainsString(
            ") === 'hosting';",
            $source,
        );

        $this->assertStringContainsString(
            '$editItem = $linkedHostingItem;',
            $source,
        );

        $this->assertStringContainsString(
            '$editItem->product->category',
            $source,
        );

        $this->assertStringContainsString(
            "'edit' => " . '$editItem->id',
            $source,
        );

        $this->assertStringContainsString(
            'href="{{ $editUrl }}"',
            $source,
        );

        $this->assertStringNotContainsString(
            "'edit' => " . '$item->id',
            $source,
        );
    }
    public function test_managed_modes_compose_registrar_contract(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4)
            . '/extensions/Servers/Hestia/Hestia.php',
        );

        $this->assertIsString($source);
        $this->assertStringContainsString(
            'ExtensionHelper::getCheckoutConfig(',
            $source,
        );
        $this->assertStringContainsString(
            'ExtensionHelper::getCheckoutPrice(',
            $source,
        );
        $this->assertStringContainsString(
            "=== 'domain_action'",
            $source,
        );
    }
}
