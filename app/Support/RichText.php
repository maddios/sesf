<?php

namespace App\Support;

/**
 * Admin-authored event text (car_show short_description, description, schedule) is either legacy plain text or
 * HTML from the rich text editor. Legacy pages printed the plain text escaped, so text without any markup is still
 * escaped (it renders exactly as before); text with markup goes through HtmlSanitizer.
 */
final class RichText
{
    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    public static function hasMarkup(?string $value): bool
    {
        return $value !== null && preg_match('/<(\/?[a-z]|!--)/i', $value) === 1;
    }

    /**
     * Safe HTML for v-html (SafeHtml.vue).
     */
    public function html(?string $value): string
    {
        if (! self::hasMarkup($value)) {
            return htmlspecialchars(trim((string) $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $this->sanitizer->sanitize($value);
    }

    /**
     * Plain text for summaries and truncation. Text without markup is only stripped and trimmed (the legacy rule);
     * HTML keeps a space where blocks and line breaks were, entities are decoded and whitespace is collapsed.
     */
    public static function plain(?string $value): string
    {
        if (! self::hasMarkup($value)) {
            return trim(strip_tags((string) $value));
        }

        $spaced = (string) preg_replace('/<(br|\/?(p|div|li|ul|ol|h[1-6]|tr|td|th|table|blockquote|center|hr))\b[^>]*>/i', ' $0', (string) $value);
        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }
}
