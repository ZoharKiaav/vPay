<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class CheckoutPricePresentationStateTest extends TestCase
{
    public function test_hosting_presentation_comes_from_hosting_total_and_plan(): void
    {
        $source = $this->source(
            'app/Livewire/Products/Checkout.php',
        );

        self::assertStringContainsString(
            'public string $hostingAmount',
            $source,
        );

        self::assertStringContainsString(
            'public string $hostingCycleLabel',
            $source,
        );

        self::assertStringContainsString(
            '$this->hostingTotal->formatted->total',
            $source,
        );

        self::assertStringContainsString(
            '$this->plan->billing_period',
            $source,
        );

        self::assertStringContainsString(
            '$this->plan->billing_unit',
            $source,
        );

        self::assertStringContainsString(
            "'services.billing_cycles.'",
            $source,
        );
    }

    public function test_domain_presentation_is_kept_separate_from_hosting(): void
    {
        $source = $this->source(
            'app/Livewire/Products/Checkout.php',
        );

        self::assertStringContainsString(
            'public string $domainAmount',
            $source,
        );

        self::assertStringContainsString(
            'public float $domainAmountValue',
            $source,
        );

        self::assertStringContainsString(
            '$this->domainTotal->formatted->total',
            $source,
        );

        self::assertStringContainsString(
            '$this->domainTotal->price',
            $source,
        );

        self::assertStringContainsString(
            '\'price\' => $total + $domainPrice',
            $source,
        );
    }

    public function test_checkout_summary_uses_scalar_presentation_values(): void
    {
        $source = $this->source(
            'themes/default/views/products/checkout.blade.php',
        );

        self::assertStringContainsString(
            '{{ $hostingAmount }}',
            $source,
        );

        self::assertStringContainsString(
            '{{ $hostingCycleLabel }}',
            $source,
        );

        self::assertStringContainsString(
            '{{ $domainAmount }}',
            $source,
        );

        self::assertStringContainsString(
            '$domainAmountValue > 0',
            $source,
        );

        self::assertStringContainsString(
            '{{ $total }}',
            $source,
        );

        self::assertStringNotContainsString(
            '{{ $hostingTotal }}',
            $source,
        );

        self::assertStringNotContainsString(
            '{{ $domainTotal }}',
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