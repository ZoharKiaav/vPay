<?php

namespace App\Admin\Resources\ServiceResource\Widgets;

use App\Models\Service;
use Filament\Widgets\Widget;

class LinkedServiceOverview extends Widget
{
    protected string $view = 'admin.resources.service-resource.widgets.linked-service-overview';

    protected static bool $isLazy = false;

    public ?Service $record = null;

    protected array|string|int $columnSpan = 'full';

    public function getLinkedService(): ?Service
    {
        if (!$this->record) {
            return null;
        }

        return $this->record->linkedDomainService
            ?? $this->record->linkedHostingService;
    }

    public function getLinkedServiceType(): ?string
    {
        if (!$this->record) {
            return null;
        }

        if ($this->record->linkedDomainService) {
            return 'Domain service';
        }

        if ($this->record->linkedHostingService) {
            return 'Hosting service';
        }

        return null;
    }
}
