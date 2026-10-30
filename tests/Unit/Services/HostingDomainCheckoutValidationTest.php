<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class HostingDomainCheckoutValidationTest extends TestCase
{
    public function test_optional_checkout_fields_are_nullable(): void
    {
        $source = $this->source('app/Livewire/Products/Checkout.php');

        self::assertStringContainsString("if (\$config['required'] ?? false)", $source);
        self::assertStringContainsString("\$validationRules[] = 'required';", $source);
        self::assertStringContainsString('} else {', $source);
        self::assertStringContainsString("\$validationRules[] = 'nullable';", $source);
        self::assertStringContainsString("\$validationRules[] = 'string';", $source);
    }

    public function test_external_domain_field_is_mode_gated(): void
    {
        $source = $this->source('themes/default/views/products/checkout.blade.php');

        self::assertStringContainsString("\$configOption->name === 'domain'", $source);
        self::assertStringContainsString("\$domainMode !== 'external'", $source);
        self::assertStringContainsString('$configOptionVisible = false;', $source);
    }

    public function test_transfer_code_field_is_mode_gated(): void
    {
        $source = $this->source('themes/default/views/products/checkout.blade.php');

        self::assertStringContainsString("\$configOption->name === 'epp_code'", $source);
        self::assertStringContainsString("\$domainMode === 'transfer'", $source);
        self::assertStringContainsString("\$domainAction === 'transfer'", $source);
        self::assertStringContainsString('@if ($configOptionVisible)', $source);
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        $source = file_get_contents($path);

        self::assertIsString($source);

        return $source;
    }
}
