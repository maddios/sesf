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
 * - Attributes are kept only when listed for that element. Event handler attributes (on*), style and
 *   anything else not listed are removed.
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
        'a' => ['href', 'title'],
        'abbr' => ['title'],
        'b' => [],
        'blockquote' => [],
        'br' => [],
        'caption' => [],
        'code' => [],
        'div' => [],
        'em' => [],
        'h1' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'h5' => [],
        'h6' => [],
        'hr' => [],
        'i' => [],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'li' => [],
        'ol' => [],
        'p' => [],
        'pre' => [],
        'small' => [],
        'span' => [],
        'strong' => [],
        'sub' => [],
        'sup' => [],
        'table' => [],
        'tbody' => [],
        'td' => ['colspan', 'rowspan'],
        'tfoot' => [],
        'th' => ['colspan', 'rowspan'],
        'thead' => [],
        'tr' => [],
        'u' => [],
        'ul' => [],
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
            }
        }
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
