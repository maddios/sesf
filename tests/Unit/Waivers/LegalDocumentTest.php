<?php

namespace Tests\Unit\Waivers;

use App\Models\CarShow;
use App\Services\Waivers\LegalDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The legal text and its two computed parts (date range, seasonal infectious-disease banner). */
class LegalDocumentTest extends TestCase
{
    private function show(?string $start, ?string $end = null, string $name = 'Spring Fling'): CarShow
    {
        return (new CarShow)->forceFill(['id' => 3, 'name' => $name, 'date_start' => $start, 'date_end' => $end ?? $start]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function seasons(): array
    {
        return [
            'january is winter of the same year' => ['2027-01-15', 'WINTER 2027'],
            'march is spring' => ['2026-03-01', 'SPRING 2026'],
            'may is spring' => ['2026-05-31', 'SPRING 2026'],
            'june is summer' => ['2026-06-01', 'SUMMER 2026'],
            'october is fall' => ['2026-10-20', 'FALL 2026'],
            'december is winter' => ['2026-12-05', 'WINTER 2026'],
        ];
    }

    #[Test]
    #[DataProvider('seasons')]
    public function the_infectious_banner_suffix_follows_the_event_start(string $start, string $expected): void
    {
        $this->assertSame($expected, LegalDocument::infectiousBannerSuffix($this->show($start)));
    }

    #[Test]
    public function without_a_start_date_the_banner_uses_the_current_year(): void
    {
        $this->assertSame(now()->format('Y'), LegalDocument::infectiousBannerSuffix($this->show(null)));
        $this->assertSame('', LegalDocument::formatEventDateRange($this->show(null)));
    }

    #[Test]
    public function the_body_contains_the_legal_clauses_banner_and_health_email(): void
    {
        $html = LegalDocument::htmlBody($this->show('2026-04-10', '2026-04-12'), 'health@example.test');

        $this->assertStringContainsString('RELEASE AND WAIVER OF LIABILITY', $html);
        $this->assertStringContainsString('EVENT DATES - April 10, 2026 - April 12, 2026', $html);
        $this->assertStringContainsString('SPRING FLING EVENT INFECTIOUS DISEASE ACKNOWLEDGEMENT AND WAIVER – SPRING 2026', $html);
        $this->assertStringContainsString('<strong>health@example.test</strong>', $html);
        $this->assertStringContainsString('<strong>9.</strong> The undersigned consents to execute this Agreement electronically', $html);
        $this->assertStringContainsString('I AM OVER THE AGE OF EIGHTEEN', $html);
    }

    #[Test]
    public function the_blank_paper_form_omits_the_electronic_signature_clause(): void
    {
        $html = LegalDocument::pdfLegalPrefix($this->show('2026-04-10'), 'h@example.test', true);

        $this->assertStringContainsString('<strong>8.</strong>', $html);
        $this->assertStringNotContainsString('<strong>9.</strong>', $html);
        $this->assertStringContainsString('page-break-before:always', $html);
    }

    #[Test]
    public function the_event_name_is_escaped(): void
    {
        $html = LegalDocument::htmlBody($this->show('2026-04-10', null, '<script>x</script>'), 'h@example.test');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
    }
}
