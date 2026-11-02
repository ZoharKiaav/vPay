<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class LinkedCartCheckoutCorrectionTest extends TestCase
{
    public function test_linked_hosting_skips_extension_checkout_price(): void
    {
        $source = $this->source(
            'app/Models/CartItem.php',
        );

        self::assertStringContainsString(
            "'hosting_domain_link_role'",
            $source,
        );

        self::assertStringContainsString(
            '$linkedRole',
            $source,
        );

        self::assertStringContainsString(
            '$linkedRole !== \'hosting\'',
            $source,
        );

        self::assertStringContainsString(
            'ExtensionHelper::getCheckoutPrice(',
            $source,
        );

        self::assertStringContainsString(
            'if ($linkedRole !== \'hosting\') {',
            $source,
        );

        self::assertStringContainsString(
            '$total += ExtensionHelper::getCheckoutPrice(',
            $source,
        );
    }
    public function test_non_hosting_items_keep_extension_checkout_price(): void
    {
        $source = $this->source(
            'app/Models/CartItem.php',
        );

        self::assertSame(
            1,
            substr_count(
                $source,
                'ExtensionHelper::getCheckoutPrice(',
            ),
        );

        self::assertStringContainsString(
            '$this->product',
            $source,
        );

        self::assertStringContainsString(
            '$checkoutConfig',
            $source,
        );

        self::assertStringNotContainsString(
            "\$linkedRole === 'registrar'",
            $source,
        );
    }

    public function test_hosting_item_preserves_canonical_edit_state(): void
    {
        $source = $this->source(
            'app/Services/HostingDomainCartPayloadBuilder.php',
        );

        self::assertStringContainsString(
            '$canonicalEditingConfig = [',
            $source,
        );

        foreach ([
            "'registration_period'",
            "'use_custom_nameservers'",
            "'nameserver_1'",
            "'nameserver_2'",
            "'nameserver_3'",
            "'nameserver_4'",
            "'nameserver_5'",
        ] as $expected) {
            self::assertStringContainsString(
                $expected,
                $source,
            );
        }

        self::assertMatchesRegularExpression(
            '/\$hostingCheckoutConfig = array_merge\(\s*'
            . '\$canonicalEditingConfig,\s*'
            . '\$selection->toCheckoutConfig\(\),/s',
            $source,
        );

        self::assertStringContainsString(
            'self::LINK_ROLE_KEY => self::ROLE_HOSTING',
            $source,
        );
    }

    public function test_checkout_skips_only_null_properties(): void
    {
        $source = $this->source(
            'app/Livewire/Cart.php',
        );

        self::assertMatchesRegularExpression(
            '/foreach \(\$item->checkout_config as '
            . '\$key => \$value\) \{\s*'
            . 'if \(\$value === null\) \{\s*'
            . 'continue;\s*'
            . '\}/s',
            $source,
        );

        self::assertStringContainsString(
            '$service->properties()->updateOrCreate([',
            $source,
        );

        self::assertStringContainsString(
            "'value' => \$value",
            $source,
        );

        self::assertStringNotContainsString(
            'if (!$value)',
            $source,
        );

        self::assertStringNotContainsString(
            'if (empty($value))',
            $source,
        );
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $relativePath,
            );

        $source = file_get_contents($path);

        self::assertIsString($source);

        return $source;
    }
}