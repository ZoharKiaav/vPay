<?php

namespace Tests\Unit\Extensions\Servers;

use Exception;
use Illuminate\Support\Facades\Http;
use Paymenter\Extensions\Servers\Stacker\Stacker;
use Tests\TestCase;

require_once dirname(__DIR__, 4)
    . '/extensions/Servers/Stacker/Stacker.php';

class StackerTemplateCatalogueConfigTest extends TestCase
{
    private function client(): Stacker
    {
        return new Stacker([
            'host' => 'https://stacker.example.test',
            'api_key' => 'catalogue-key',
            'verify_tls' => true,
            'timeout' => 30,
        ]);
    }

    private function catalogueResponse(): array
    {
        return [
            'templates' => [
                [
                    'id' => 'clientops-starter',
                    'versions' => [
                        [
                            'version' => '1.0.3',
                            'active' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_requests_approved_template_catalogue(): void
    {
        Http::fake([
            'https://stacker.example.test/api/vkloud/provisioning/v1/templates'
                => Http::response(
                    $this->catalogueResponse(),
                    200,
                ),
        ]);

        $response = $this->client()->templateCatalogue();

        $this->assertSame(
            $this->catalogueResponse(),
            $response,
        );

        Http::assertSentCount(1);

        Http::assertSent(
            fn ($request): bool =>
                $request->method() === 'GET'
                && $request->url()
                    === 'https://stacker.example.test/api/vkloud/provisioning/v1/templates'
                && $request->hasHeader(
                    'x-api-key',
                    'catalogue-key',
                ),
        );
    }

    public function test_builds_catalogue_backed_product_selectors(): void
    {
        Http::fake([
            '*' => Http::response(
                $this->catalogueResponse(),
                200,
            ),
        ]);

        $config = $this->client()->getProductConfig([
            'workload_type' => 'vpstack',
            'product_id' => 'clientops',
            'template_id' => 'clientops-starter',
            'template_version' => '1.0.3',
        ]);

        $byName = collect($config)->keyBy('name');

        $this->assertSame(
            'select',
            $byName['template_id']['type'],
        );

        $this->assertTrue(
            $byName['template_id']['live'],
        );

        $this->assertSame(
            [
                [
                    'label' => 'clientops-starter',
                    'value' => 'clientops-starter',
                ],
            ],
            $byName['template_id']['options'],
        );

        $this->assertSame(
            'select',
            $byName['template_version']['type'],
        );

        $this->assertFalse(
            $byName['template_version']['disabled'],
        );

        $this->assertSame(
            [
                [
                    'label' => '1.0.3',
                    'value' => '1.0.3',
                ],
            ],
            $byName['template_version']['options'],
        );

        Http::assertSentCount(1);
    }

    public function test_version_selector_is_disabled_until_template_selected(): void
    {
        Http::fake([
            '*' => Http::response(
                $this->catalogueResponse(),
                200,
            ),
        ]);

        $config = $this->client()->getProductConfig([]);

        $byName = collect($config)->keyBy('name');

        $this->assertTrue(
            $byName['template_version']['disabled'],
        );

        $this->assertSame(
            [],
            $byName['template_version']['options'],
        );
    }

    public function test_rejects_unapproved_saved_template(): void
    {
        Http::fake([
            '*' => Http::response(
                $this->catalogueResponse(),
                200,
            ),
        ]);

        $this->expectException(Exception::class);

        $this->expectExceptionMessage(
            'Selected Stacker template is not approved',
        );

        $this->client()->getProductConfig([
            'template_id' => 'unapproved-template',
        ]);
    }

    public function test_rejects_unapproved_saved_version(): void
    {
        Http::fake([
            '*' => Http::response(
                $this->catalogueResponse(),
                200,
            ),
        ]);

        $this->expectException(Exception::class);

        $this->expectExceptionMessage(
            'Selected Stacker template version is not approved',
        );

        $this->client()->getProductConfig([
            'template_id' => 'clientops-starter',
            'template_version' => '1.0.2',
        ]);
    }

    public function test_rejects_invalid_catalogue_shape(): void
    {
        $this->expectException(Exception::class);

        $this->expectExceptionMessage(
            'Stacker template catalogue has no templates array',
        );

        $this->client()->approvedTemplateCatalogue([
            'unexpected' => [],
        ]);
    }

    public function test_omits_inactive_versions(): void
    {
        $catalogue =
            $this->client()->approvedTemplateCatalogue([
                'templates' => [
                    [
                        'id' => 'clientops-starter',
                        'versions' => [
                            [
                                'version' => '1.0.2',
                                'active' => false,
                            ],
                            [
                                'version' => '1.0.3',
                                'active' => true,
                            ],
                        ],
                    ],
                ],
            ]);

        $this->assertSame(
            [
                'clientops-starter' => [
                    '1.0.3' => '1.0.3',
                ],
            ],
            $catalogue,
        );
    }
}