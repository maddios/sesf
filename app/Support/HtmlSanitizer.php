<?php

namespace App\Support;

use DOMAttr;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;

/**
 * Allow-list HTML sanitizer for admin-authored fragments (show schedules, agenda text).
 *
 * - Elements not in ALLOWED_ELEMENTS are removed with their content when they are in DROP_ELEMENTS
 *   (scripts, frames, embedded objects, forms, SVG/MathML), otherwise only the tag is removed and the
 *   children are kept.
 * - Attributes are kept only when listed for that element. Event handler attributes (on*) and anything
 *   else not listed are removed. class keeps only the legacy Bootstrap layout tokens in ALLOWED_CLASSES, style
 *   keeps only the declarations in ALLOWED_STYLES with plain length or keyword values, align keeps
 *   left/center/right/justify. Legacy content relies on these for centring, image sizes and its buttons.
 * - The admin rich text editor (RichTextEditor.vue) adds color (hex, rgb(), hsl() or a name), font-size (a bounded
 *   px/pt/em/rem/% value or a keyword) and font-family (only the editor's own stacks, EDITOR_FONT_STACKS).
 *   Values are matched whole, so comments, escapes, url(), expression() and similar never pass.
 * - href allows http, https, mailto, tel and relative URLs. src allows http, https and relative URLs
 *   (data: URIs are refused). Scheme checks ignore case and control or whitespace characters, so
 *   "java\tscript:" is caught.
 * - HTML comments and processing instructions are removed.
 *
 * Output is a serialised HTML fragment, not a full document.
 */
final class HtmlSanitizer
{
    /**
     * @var array<string, list<string>> element name => attributes allowed on it
     */
    private const ALLOWED_ELEMENTS = [
        'a' => ['href', 'title', 'class', 'style'],
        'abbr' => ['title'],
        'b' => [],
        'big' => [],
        'blockquote' => [],
        'br' => [],
        'caption' => [],
        'center' => [],
        'code' => [],
        'div' => ['class', 'style', 'align'],
        'em' => [],
        'h1' => ['class', 'style', 'align'],
        'h2' => ['class', 'style', 'align'],
        'h3' => ['class', 'style', 'align'],
        'h4' => ['class', 'style', 'align'],
        'h5' => ['class', 'style', 'align'],
        'h6' => ['class', 'style', 'align'],
        'hr' => [],
        'i' => [],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'class', 'style', 'align'],
        'li' => [],
        'ol' => [],
        'p' => ['class', 'style', 'align'],
        'pre' => [],
        's' => [],
        'small' => [],
        'span' => ['class', 'style'],
        'strong' => [],
        'sub' => [],
        'sup' => [],
        'table' => ['class', 'style', 'align'],
        'tbody' => [],
        'td' => ['colspan', 'rowspan', 'class', 'style', 'align'],
        'tfoot' => [],
        'th' => ['colspan', 'rowspan', 'class', 'style', 'align'],
        'thead' => [],
        'tr' => [],
        'u' => [],
        'ul' => [],
    ];

    /**
     * Legacy Bootstrap classes the content uses for buttons, alignment and floats.
     */
    private const ALLOWED_CLASSES = '/^(btn|btn-(default|primary|info|success|warning|danger|link|large|lg|small|sm|mini|xs|block)|text-(left|center|right|justify)|pull-(left|right)|img-responsive|img-rounded|img-polaroid|lead)$/';

    private const LENGTH = '(auto|0|\\d{1,4}(\\.\\d{1,2})?(px|%|em|rem))';

    /**
     * @var array<string, string> CSS property => pattern its whole value must match
     */
    private const ALLOWED_STYLES = [
        'text-align' => '/^(left|right|center|justify)$/',
        'float' => '/^(left|right|none)$/',
        'width' => '/^'.self::LENGTH.'$/',
        'height' => '/^'.self::LENGTH.'$/',
        'max-width' => '/^'.self::LENGTH.'$/',
        'margin' => '/^'.self::LENGTH.'( '.self::LENGTH.'){0,3}$/',
        'margin-top' => '/^'.self::LENGTH.'$/',
        'margin-right' => '/^'.self::LENGTH.'$/',
        'margin-bottom' => '/^'.self::LENGTH.'$/',
        'margin-left' => '/^'.self::LENGTH.'$/',
        'font-weight' => '/^(normal|bold|[1-9]00)$/',
        'font-style' => '/^(normal|italic)$/',
        'color' => '/^(#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})|[a-z]{3,20}'
            .'|rgba?\\(\\s*\\d{1,3}(\\.\\d{1,3})?%?(\\s*,\\s*\\d{1,3}(\\.\\d{1,3})?%?){2}(\\s*,\\s*(0|1|0?\\.\\d{1,3}|\\d{1,3}%))?\\s*\\)'
            .'|hsla?\\(\\s*\\d{1,3}(\\.\\d{1,3})?(deg)?(\\s*,\\s*\\d{1,3}(\\.\\d{1,3})?%){2}(\\s*,\\s*(0|1|0?\\.\\d{1,3}|\\d{1,3}%))?\\s*\\))$/',
        'font-size' => '/^((\\d{1,3}(\\.\\d{1,2})?)(px|pt|em|rem|%)|xx-small|x-small|small|medium|large|x-large|xx-large|xxx-large|smaller|larger)$/',
    ];

    /**
     * Bounds for font-size numbers, per unit.
     *
     * @var array<string, array{0: int|float, 1: int|float}>
     */
    private const FONT_SIZE_RANGES = [
        'px' => [6, 72],
        'pt' => [5, 54],
        'em' => [0.5, 5],
        'rem' => [0.5, 5],
        '%' => [50, 400],
    ];

    /**
     * Font stacks the rich text editor offers (EDITOR_FONTS in resources/js/components/admin/richText.ts).
     *
     * @var list<string>
     */
    public const EDITOR_FONT_STACKS = [
        'Arial, Helvetica, sans-serif',
        'Georgia, serif',
        "'Times New Roman', Times, serif",
        'Verdana, Geneva, sans-serif',
        "'Trebuchet MS', Helvetica, sans-serif",
        "'Courier New', Courier, monospace",
    ];

    /**
     * @var list<string>
     */
    private const DROP_ELEMENTS = [
        'applet', 'base', 'button', 'embed', 'form', 'frame', 'frameset', 'head', 'iframe', 'input', 'link',
        'math', 'meta', 'noscript', 'object', 'option', 'script', 'select', 'style', 'svg', 'template',
        'textarea', 'title', 'xml',
    ];

    /**
     * @var array<string, list<string>> attribute name => URL schemes allowed for it
     */
    private const URL_SCHEMES = [
        'href' => ['http', 'https', 'mailto', 'tel'],
        'src' => ['http', 'https'],
    ];

    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
            LIBXML_NONET,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $document->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }

        $this->cleanChildren($body);

        $output = '';
        foreach ($body->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return $output;
    }

    private function cleanChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMComment || $node instanceof DOMProcessingInstruction) {
                $parent->removeChild($node);

                continue;
            }

            if (! $node instanceof DOMElement) {
                continue;
            }

            $name = strtolower($node->tagName);

            if (! array_key_exists($name, self::ALLOWED_ELEMENTS)) {
                if (in_array($name, self::DROP_ELEMENTS, true)) {
                    $parent->removeChild($node);

                    continue;
                }

                $this->cleanChildren($node);
                $this->unwrap($parent, $node);

                continue;
            }

            $this->cleanAttributes($node, $name);

            if ($name === 'img' && ! $node->hasAttribute('src')) {
                $parent->removeChild($node);

                continue;
            }

            $this->cleanChildren($node);
        }
    }

    private function cleanAttributes(DOMElement $element, string $name): void
    {
        /** @var list<DOMAttr> $attributes */
        $attributes = iterator_to_array($element->attributes);

        foreach ($attributes as $attribute) {
            $attributeName = strtolower($attribute->name);

            if (! in_array($attributeName, self::ALLOWED_ELEMENTS[$name], true)) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            if (isset(self::URL_SCHEMES[$attributeName]) && ! $this->isSafeUrl($attribute->value, self::URL_SCHEMES[$attributeName])) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            if (in_array($attributeName, ['colspan', 'rowspan', 'width', 'height'], true) && preg_match('/^\d{1,4}$/', $attribute->value) !== 1) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            $filtered = match ($attributeName) {
                'class' => $this->filterClasses($attribute->value),
                'style' => $this->filterStyle($attribute->value),
                'align' => preg_match('/^(left|center|right|justify)$/i', trim($attribute->value)) === 1 ? strtolower(trim($attribute->value)) : '',
                default => null,
            };

            if ($filtered === '') {
                $element->removeAttributeNode($attribute);
            } elseif ($filtered !== null) {
                $element->setAttribute($attributeName, $filtered);
            }
        }
    }

    private function filterClasses(string $value): string
    {
        $tokens = preg_split('/\s+/', strtolower(trim($value))) ?: [];

        return implode(' ', array_filter($tokens, fn (string $token): bool => preg_match(self::ALLOWED_CLASSES, $token) === 1));
    }

    private function filterStyle(string $value): string
    {
        $kept = [];

        foreach (explode(';', $value) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $property = strtolower(trim($parts[0]));
            $propertyValue = strtolower((string) preg_replace('/\s+/', ' ', trim($parts[1])));
            $allowed = $property === 'font-family' ? $this->editorFontStack($propertyValue) : $this->styleValue($property, $propertyValue);

            if ($allowed !== null) {
                $kept[] = $property.': '.$allowed;
            }
        }

        return implode('; ', $kept);
    }

    private function styleValue(string $property, string $value): ?string
    {
        if (! isset(self::ALLOWED_STYLES[$property]) || preg_match(self::ALLOWED_STYLES[$property], $value, $matches) !== 1) {
            return null;
        }

        if ($property === 'font-size' && isset($matches[4]) && $matches[4] !== '') {
            [$min, $max] = self::FONT_SIZE_RANGES[$matches[4]];
            $number = (float) $matches[2];

            return $number >= $min && $number <= $max ? $value : null;
        }

        return $value;
    }

    private function editorFontStack(string $value): ?string
    {
        foreach (self::EDITOR_FONT_STACKS as $stack) {
            if (self::fontKey($stack) === self::fontKey($value)) {
                return $stack;
            }
        }

        return null;
    }

    /**
     * Compares stacks without quotes, case or spacing differences ("times new roman",times vs 'Times New Roman', Times).
     */
    private static function fontKey(string $stack): string
    {
        return (string) preg_replace('/\s*,\s*/', ',', strtolower(str_replace(['"', "'"], '', trim($stack))));
    }

    /**
     * @param  list<string>  $allowedSchemes
     */
    private function isSafeUrl(string $value, array $allowedSchemes): bool
    {
        $normalised = preg_replace('/[\x00-\x20\x7F]+/', '', $value) ?? '';

        if ($normalised === '') {
            return false;
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $normalised, $matches) === 1) {
            return in_array(strtolower($matches[1]), $allowedSchemes, true);
        }

        return true;
    }

    /**
     * Moves the children of $node up into $parent, in place of $node, then removes $node.
     */
    private function unwrap(DOMNode $parent, DOMNode $node): void
    {
        while ($node->firstChild !== null) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }
}
