<?php

namespace App\Services\Service;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Exception;

class ServerProvisioningService
{
    public function create(Service $service): array
    {
        try {
            $data = ExtensionHelper::createServer($service);
        } catch (Exception $exception) {
            if (
                $exception->getMessage()
                !== 'No server assigned to this product'
            ) {
                throw $exception;
            }

            return [];
        }

        return is_array($data)
            ? $data
            : [];
    }
}