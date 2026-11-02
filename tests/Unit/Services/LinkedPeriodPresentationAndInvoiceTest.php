<?php

namespace Tests\Unit\Services;

use Tests\TestCase;

class LinkedPeriodPresentationAndInvoiceTest extends TestCase
{
    public function test_checkout_refreshes_service_before_invoice_description(): void
    {
        $source = file_get_contents(
            app_path('Livewire/Cart.php'),
        );

        self::assertIsString($source);

        $propertyIndex = strrpos(
            substr(
                $source,
                0,
                strpos($source, '$service->refresh();'),
            ),
            '$service->properties()->updateOrCreate([',
        );

        $refreshIndex = strpos(
            $source,
            '$service->refresh();',
        );

        $invoiceIndex = strpos(
            $source,
            '$invoice->items()->create([',
        );

        self::assertNotFalse($propertyIndex);
        self::assertNotFalse($refreshIndex);
        self::assertNotFalse($invoiceIndex);

        self::assertLessThan(
            $refreshIndex,
            $propertyIndex,
        );

        self::assertLessThan(
            $invoiceIndex,
            $refreshIndex,
        );

        self::assertStringContainsString(
            "'description' => \$service->description",
            $source,
        );
    }

    public function test_cart_period_labels_are_contextual(): void
    {
        $source = file_get_contents(
            base_path(
                'themes/default/views/cart.blade.php',
            ),
        );

        self::assertIsString($source);

        self::assertStringContainsString(
            '$item->plan->billing_period',
            $source,
        );

        self::assertStringContainsString(
            '$item->plan->billing_unit',
            $source,
        );

        self::assertStringContainsString(
            'Billing period',
            $source,
        );

        self::assertStringContainsString(
            "'Domain registration'",
            $source,
        );

        self::assertStringContainsString(
            "'Registration period'",
            $source,
        );

        self::assertStringContainsString(
            '$registrationPeriod',
            $source,
        );

        self::assertStringContainsString(
            "\$linkedRole === 'hosting'",
            $source,
        );

        self::assertStringNotContainsString(
            <<<'BLADE'
                            <dt class="text-primary-100/70">
                                Period
                            </dt>
            BLADE,
            $source,
        );
    }
}