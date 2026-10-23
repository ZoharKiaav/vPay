<?php

namespace App\Services;

use App\Models\Product;

class HostingDomainCheckoutService
{
    public function __construct(
        private readonly HostingRegistrarProductResolver $resolver,
        private readonly HostingDomainSelectionService $selectionService,
    ) {}

    public function prepare(
        Product $hostingProduct,
        array $customerValues,
    ): HostingDomainSelection {
        unset($customerValues['registrar_product_id']);

        $mode = strtolower(trim((string) (
            $customerValues['domain_mode'] ?? ''
        )));

        if (in_array(
            $mode,
            [
                HostingDomainSelection::MODE_REGISTER,
                HostingDomainSelection::MODE_TRANSFER,
            ],
            true,
        )) {
            $registrarProduct = $this->resolver->resolve(
                $hostingProduct,
            );

            $customerValues['registrar_product_id'] =
                $registrarProduct->id;
        }

        return $this->selectionService->make($customerValues);
    }

    public function prepareConfig(
        Product $hostingProduct,
        array $customerValues,
    ): array {
        return $this
            ->prepare($hostingProduct, $customerValues)
            ->toCheckoutConfig();
    }
}
