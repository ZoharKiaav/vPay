<?php

namespace Tests\Feature\Services;

use PHPUnit\Framework\TestCase;

class LinkedServiceVisibilityTest extends TestCase
{
    public function test_admin_page_registers_linked_widget_and_preserves_existing_widgets(): void
    {
        $source = $this->source(
            'app/Admin/Resources/ServiceResource/Pages/EditService.php',
        );

        self::assertStringContainsString(
            'ServiceResource\Widgets\RegistrarOrderOverview::class',
            $source,
        );

        self::assertStringContainsString(
            'ServiceResource\Widgets\LinkedServiceOverview::class',
            $source,
        );

        self::assertStringContainsString(
            'ServiceResource\Widgets\CancellationOverview::class',
            $source,
        );

        self::assertStringContainsString(
            '$this->record->linkedDomainService',
            $source,
        );

        self::assertStringContainsString(
            '$this->record->linkedHostingService',
            $source,
        );
    }

    public function test_admin_widget_resolves_both_link_directions(): void
    {
        $source = $this->source(
            'app/Admin/Resources/ServiceResource/Widgets/LinkedServiceOverview.php',
        );

        self::assertStringContainsString(
            '$this->record->linkedDomainService',
            $source,
        );

        self::assertStringContainsString(
            '$this->record->linkedHostingService',
            $source,
        );

        self::assertStringContainsString(
            "return 'Domain service';",
            $source,
        );

        self::assertStringContainsString(
            "return 'Hosting service';",
            $source,
        );

        self::assertStringContainsString(
            'return null;',
            $source,
        );
    }

    public function test_admin_view_has_guarded_status_and_valid_navigation(): void
    {
        $source = $this->source(
            'resources/views/admin/resources/service-resource/widgets/linked-service-overview.blade.php',
        );

        self::assertStringContainsString(
            '@if ($linkedService)',
            $source,
        );

        self::assertStringContainsString(
            '{{ $linkedService->label }}',
            $source,
        );

        self::assertStringContainsString(
            '{{ $linkedServiceType }}',
            $source,
        );

        self::assertStringContainsString(
            '$linkedService->status',
            $source,
        );

        self::assertMatchesRegularExpression(
            '/<a\s+href="\{\{\s*\\\\App\\\\Admin\\\\Resources\\\\ServiceResource::getUrl\(' .
            "'edit',\s*\['record'\s*=>\s*\\\$linkedService\]\)\s*\}\}\"/s",
            $source,
        );

        self::assertStringContainsString(
            'View linked service',
            $source,
        );

        self::assertStringNotContainsString(
            '"{{ $linkedService]) }}"',
            $source,
        );
    }

    public function test_customer_view_has_guarded_status_and_valid_navigation(): void
    {
        $source = $this->source(
            'themes/default/views/services/show.blade.php',
        );

        self::assertStringContainsString(
            '$service->linkedDomainService',
            $source,
        );

        self::assertStringContainsString(
            '$service->linkedHostingService',
            $source,
        );

        self::assertStringContainsString(
            '@if ($linkedService)',
            $source,
        );

        self::assertStringContainsString(
            '{{ $linkedService->label }}',
            $source,
        );

        self::assertStringContainsString(
            '$linkedService->status',
            $source,
        );

        self::assertMatchesRegularExpression(
            '/<a\s+href="\{\{\s*route\(' .
            "'services\.show',\s*\\\$linkedService\)\s*\}\}\"\s*" .
            'wire:navigate\s+class="[^"]+"\s*>/s',
            $source,
        );

        self::assertStringContainsString(
            'View linked service',
            $source,
        );

    }

    public function test_both_views_hide_linked_service_content_when_no_link_exists(): void
    {
        $adminSource = $this->source(
            'resources/views/admin/resources/service-resource/widgets/linked-service-overview.blade.php',
        );

        $customerSource = $this->source(
            'themes/default/views/services/show.blade.php',
        );

        self::assertSame(
            1,
            substr_count($adminSource, '@if ($linkedService)'),
        );

        self::assertSame(
            1,
            substr_count($customerSource, '@if ($linkedService)'),
        );
    }

    private function source(string $relativePath): string
    {
        $repositoryRoot = dirname(__DIR__, 3);
        $path = $repositoryRoot . DIRECTORY_SEPARATOR . $relativePath;
        $source = file_get_contents($path);

        self::assertNotFalse(
            $source,
            "Unable to read source file: {$relativePath}",
        );

        return $source;
    }
}
