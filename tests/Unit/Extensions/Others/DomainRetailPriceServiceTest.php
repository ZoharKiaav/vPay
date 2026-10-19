<?php

namespace Tests\Unit\Extensions\Others;

use InvalidArgumentException;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Services\DomainRetailPriceService;
use PHPUnit\Framework\TestCase;

class DomainRetailPriceServiceTest extends TestCase
{
    public function test_it_applies_percentage_and_fixed_markup(): void
    {
        $price = $this->service()->calculate(
            providerPrice: '93.00',
            period: 1,
            settings: [
                'markup_percentage' => '25',
                'markup_fixed' => '5',
            ],
        );

        $this->assertSame(121.25, $price);
    }

    public function test_it_charges_for_the_selected_period(): void
    {
        $price = $this->service()->calculate(
            providerPrice: '93.00',
            period: 2,
            settings: [
                'markup_percentage' => '25',
                'markup_fixed' => '5',
            ],
        );

        $this->assertSame(242.50, $price);
    }

    public function test_it_preserves_provider_price_without_markup(): void
    {
        $price = $this->service()->calculate(
            providerPrice: '93.00',
            period: 1,
        );

        $this->assertSame(93.00, $price);
    }

    public function test_it_rounds_the_retail_price_to_two_decimals(): void
    {
        $price = $this->service()->calculate(
            providerPrice: '99.99',
            period: 1,
            settings: [
                'markup_percentage' => '17.5',
                'markup_fixed' => '3.33',
            ],
        );

        $this->assertSame(120.82, $price);
    }

    public function test_it_rejects_a_negative_provider_price(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The provider price must be a non-negative number.',
        );

        $this->service()->calculate(
            providerPrice: '-1.00',
            period: 1,
        );
    }

    public function test_it_rejects_a_zero_registration_period(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The registration period must be a positive integer.',
        );

        $this->service()->calculate(
            providerPrice: '93.00',
            period: 0,
        );
    }

    public function test_it_rejects_negative_markup(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The percentage markup must be a non-negative number.',
        );

        $this->service()->calculate(
            providerPrice: '93.00',
            period: 1,
            settings: [
                'markup_percentage' => '-1',
            ],
        );
    }

    private function service(): DomainRetailPriceService
    {
        return new DomainRetailPriceService;
    }
}
