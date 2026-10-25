<?php

namespace Tests\Unit\Support;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HtmlSanitizerTest extends TestCase
{
    #[Test]
    public function null_and_blank_input_give_an_empty_string(): void
    {
        $sanitizer = new HtmlSanitizer;

        $this->assertSame('', $sanitizer->sanitize(null));
        $this->assertSame('', $sanitizer->sanitize("   \n "));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function sanitizerTable(): array
    {
        return [
            'script tag is removed with its content' => [
                '<p>Hi</p><script>alert(1)</script>',
                '<p>Hi</p>',
                'alert',
            ],
            'onerror attribute is removed and the image kept' => [
                '<img src="/images/a.jpg" onerror="alert(1)">',
                '<img src="/images/a.jpg">',
                'onerror',
            ],
            'onclick attribute is removed' => [
                '<p onclick="steal()">Text</p>',
                '<p>Text</p>',
                'onclick',
            ],
            'javascript href is removed and the link text kept' => [
                '<a href="javascript:alert(1)">Click</a>',
                '<a>Click</a>',
                'javascript',
            ],
            'javascript href with whitespace and case tricks is removed' => [
                '<a href="  JaVa&#x09;Script:alert(1)">Click</a>',
                '<a>Click</a>',
                'alert',
            ],
            'data uri image is removed' => [
                '<img src="data:image/svg+xml;base64,PHN2Zz4=">',
                '',
                'data:',
            ],
            'iframe is removed with its content' => [
                '<p>Before</p><iframe src="https://evil.example"></iframe><p>After</p>',
                '<p>Before</p><p>After</p>',
                'iframe',
            ],
            'style element and style attribute are removed' => [
                '<style>body{display:none}</style><p style="color:red">Styled</p>',
                '<p>Styled</p>',
                'display',
            ],
            'unknown element is unwrapped and its text kept' => [
                '<font color="red">Old <blink>tag</blink></font>',
                'Old tag',
                'font',
            ],
            'html comments are removed' => [
                '<p>A</p><!-- secret note -->',
                '<p>A</p>',
                'secret',
            ],
            'allowed formatting is kept' => [
                '<p><b>Bold</b> <i>it</i> <a href="https://example.org/x" title="t">link</a></p>'
                    .'<ul><li>one</li></ul><br><img src="/images/shows/1.jpg" alt="pic">',
                '<p><b>Bold</b> <i>it</i> <a href="https://example.org/x" title="t">link</a></p>'
                    .'<ul><li>one</li></ul><br><img src="/images/shows/1.jpg" alt="pic">',
                '',
            ],
            'mailto and relative links are kept' => [
                '<a href="mailto:info@example.org">Mail</a> <a href="/event/e1">Local</a>',
                '<a href="mailto:info@example.org">Mail</a> <a href="/event/e1">Local</a>',
                '',
            ],
            'table cells keep numeric spans only' => [
                '<table><tr><td colspan="2" rowspan="x">Cell</td></tr></table>',
                '<table><tr><td colspan="2">Cell</td></tr></table>',
                'rowspan',
            ],
            'form controls are removed' => [
                '<form action="/steal"><input name="pw"><button>Go</button></form><p>Kept</p>',
                '<p>Kept</p>',
                'steal',
            ],
            'svg payload is removed' => [
                '<svg onload="alert(1)"><script>alert(2)</script></svg><p>Ok</p>',
                '<p>Ok</p>',
                'svg',
            ],
            'escaped text stays escaped' => [
                '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
                '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
                '',
            ],
        ];
    }

    #[Test]
    #[DataProvider('sanitizerTable')]
    public function it_sanitizes_fragments(string $input, string $expected, string $mustNotContain): void
    {
        $output = (new HtmlSanitizer)->sanitize($input);

        $this->assertSame($expected, $output);

        if ($mustNotContain !== '') {
            $this->assertStringNotContainsStringIgnoringCase($mustNotContain, $output);
        }
    }

    #[Test]
    public function it_keeps_utf8_text_intact(): void
    {
        $output = (new HtmlSanitizer)->sanitize('<p>Café – 東京</p>');

        $this->assertSame('<p>Café – 東京</p>', $output);
    }
}
