<?php

namespace Tests\Unit\Support;

use App\Support\HtmlSanitizer;
use App\Support\RichText;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Real event texts (the 20 newest car_show rows of the dev database, tests/Fixtures/legacy-event-texts.json) must
 * render exactly as before the rich text editor: the sanitizer output equals the snapshot taken with the previous
 * allow-list, and plain text stays escaped text.
 */
class LegacyEventTextTest extends TestCase
{
    private const FIELDS = ['short_description', 'description', 'schedule'];

    /**
     * @return list<array<string, string|int|null>>
     */
    private function rows(): array
    {
        /** @var list<array<string, string|int|null>> $rows */
        $rows = json_decode((string) file_get_contents(base_path('tests/Fixtures/legacy-event-texts.json')), true, flags: JSON_THROW_ON_ERROR);

        return $rows;
    }

    #[Test]
    public function the_sanitizer_still_renders_legacy_texts_as_before(): void
    {
        /** @var array<string, array<string, string>> $snapshot */
        $snapshot = json_decode((string) file_get_contents(base_path('tests/Fixtures/legacy-event-texts.sanitized.json')), true, flags: JSON_THROW_ON_ERROR);
        $sanitizer = new HtmlSanitizer;

        $this->assertCount(20, $this->rows());
        foreach ($this->rows() as $row) {
            foreach (self::FIELDS as $field) {
                $value = $row[$field] === null ? null : (string) $row[$field];
                $this->assertSame($snapshot[(string) $row['id']][$field], $sanitizer->sanitize($value), "car_show {$row['id']} {$field}");
            }
        }
    }

    #[Test]
    public function legacy_plain_text_is_still_printed_as_text(): void
    {
        $richText = new RichText(new HtmlSanitizer);

        foreach ($this->rows() as $row) {
            foreach (['short_description', 'description'] as $field) {
                $value = (string) $row[$field];
                $this->assertFalse(RichText::hasMarkup($value), "car_show {$row['id']} {$field} has no markup");
                $this->assertSame(htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8'), $richText->html($value));
                $this->assertSame(trim(strip_tags($value)), RichText::plain($value));
            }
        }
    }
}
