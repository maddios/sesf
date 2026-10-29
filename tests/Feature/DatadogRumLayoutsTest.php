<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DatadogRumLayoutsTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function layouts(): array
    {
        return [
            'waiver' => ['waiver.layout', ['config' => ['page' => 'sign']]],
            'judging' => ['judging.layout', []],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[Test]
    #[DataProvider('layouts')]
    public function the_layout_includes_rum_with_public_values_only_when_configured(string $view, array $data): void
    {
        $this->withoutVite();
        config([
            'sesf.datadog.rum.enabled' => true,
            'sesf.datadog.rum.application_id' => 'app-id-123',
            'sesf.datadog.rum.client_token' => 'pubTOKEN123',
            'sesf.datadog.logs.api_key' => 'DD-API-KEY-MUST-NOT-LEAK',
        ]);

        $html = view($view, $data)->render();

        $this->assertStringContainsString('datadog-rum.js', $html);
        $this->assertStringContainsString('app-id-123', $html);
        $this->assertStringNotContainsString('MUST-NOT-LEAK', $html);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[Test]
    #[DataProvider('layouts')]
    public function the_layout_has_no_rum_when_disabled(string $view, array $data): void
    {
        $this->withoutVite();
        config(['sesf.datadog.rum.enabled' => false]);

        $this->assertStringNotContainsString('datadog-rum.js', view($view, $data)->render());
    }
}
