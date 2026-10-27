<?php

namespace App\Services\Invoice;

use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Service\LinkedPaidProvisioningService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;

class ProcessPaidInvoiceService
{
    /**
     * Handle the processing of a paid invoice.
     */
    public function handle(Invoice $invoice): void
    {
        $services = $invoice->items
            ->filter(
                static fn ($item): bool => $item->reference_type == Service::class
                    && $item->reference instanceof Service,
            )
            ->map(
                static fn ($item): Service => $item->reference,
            );

        app(LinkedPaidProvisioningService::class)
            ->handle($services);

        $invoice->items->each(function ($item) use ($invoice) {
            if ($item->reference_type == Service::class) {
                return;
            } elseif ($item->reference_type == ServiceUpgrade::class) {
                $serviceUpgrade = $item->reference;
                if (!$serviceUpgrade || $serviceUpgrade->status !== ServiceUpgrade::STATUS_PENDING || !($serviceUpgrade instanceof ServiceUpgrade)) {
                    return;
                }

                // Handle the upgrade
                (new ServiceUpgradeService)->handle($serviceUpgrade);
            } elseif ($item->reference_type == Credit::class) {
                // Check if user has credits in this currency
                $user = $invoice->user;
                $credit = $user->credits()->where('currency_code', $invoice->currency_code)->first();

                if ($credit) {
                    $credit->amount += $item->price;
                    $credit->save();
                } else {
                    $user->credits()->create([
                        'currency_code' => $invoice->currency_code,
                        'amount' => $item->price,
                    ]);
                }
            }
        });
    }
}
