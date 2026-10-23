<?php

namespace App\Services;

use App\Models\Product;
use LogicException;

class HostingRegistrarProductResolver
{
    public const SETTING_KEY = 'registrar_product_id';

    public const REGISTRAR_EXTENSION = 'AbsoluteHostingDomains';

    public function options(): array
    {
        return Product::query()
            ->whereHas('server', function ($query): void {
                $query
                    ->where('extension', self::REGISTRAR_EXTENSION)
                    ->where('enabled', true);
            })
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function resolve(Product $hostingProduct): Product
    {
        $configuredId = $hostingProduct
            ->settings()
            ->where('key', self::SETTING_KEY)
            ->value('value');

        if (
            !is_numeric($configuredId)
            || (int) $configuredId < 1
        ) {
            throw new LogicException(
                'No registrar product is configured for this hosting product.',
            );
        }

        $registrarProduct = Product::query()
            ->with('server')
            ->find((int) $configuredId);

        if ($registrarProduct === null) {
            throw new LogicException(
                'The configured registrar product no longer exists.',
            );
        }

        if (
            $registrarProduct->server === null
            || $registrarProduct->server->extension
                !== self::REGISTRAR_EXTENSION
        ) {
            throw new LogicException(
                'The configured product is not an Absolute Hosting registrar product.',
            );
        }

        if (!$registrarProduct->server->enabled) {
            throw new LogicException(
                'The configured registrar server is disabled.',
            );
        }

        return $registrarProduct;
    }
}
