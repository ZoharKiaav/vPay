<?php

namespace App\Admin\Resources\ServiceResource\Widgets;

use App\Models\Service;
use Filament\Widgets\Widget;
use Paymenter\Extensions\Others\AbsoluteHostingDomains\Models\ManagedDomain;

class RegistrarOrderOverview extends Widget
{
    protected string $view = 'admin.resources.service-resource.widgets.registrar-order-overview';

    protected static bool $isLazy = false;

    public ?Service $record = null;

    protected array|string|int $columnSpan = 'full';

    public function getOperationalSummary(): ?array
    {
        if (!$this->record) {
            return null;
        }

        return ManagedDomain::query()
            ->where('service_id', $this->record->getKey())
            ->first()
            ?->operationalSummary();
    }
}
