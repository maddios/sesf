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
            'style element is removed, a plain colour is kept' => [
                '<style>body{display:none}</style><p style="color:red">Styled</p>',
                '<p style="color: red">Styled</p>',
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
            'legacy center and big are kept' => [
                '<p><center><big>Primary Sponsors</big></center></p>',
                '<p></p><center><big>Primary Sponsors</big></center>',
                '',
            ],
            'legacy button and alignment classes are kept, other classes dropped' => [
                '<a href="/x" class="btn btn-danger evil-overlay">Go</a><p class="text-center foo">C</p><span class="x">S</span>',
                '<a href="/x" class="btn btn-danger">Go</a><p class="text-center">C</p><span>S</span>',
                'evil',
            ],
            'style keeps sizes and alignment only' => [
                '<img src="/a.jpg" style="height:188px; width:250px; position:fixed; background:url(x)"><p style="text-align: Center;line-height:3">T</p>',
                '<img src="/a.jpg" style="height: 188px; width: 250px"><p style="text-align: center">T</p>',
                'url(',
            ],
            'style with expressions or unknown units is removed' => [
                '<div style="width: expression(alert(1)); margin: 1px 2px 3px 4px 5px">D</div>',
                '<div>D</div>',
                'expression',
            ],
            'align keeps keywords only' => [
                '<p align="CENTER">A</p><td align="javascript:x">B</td>',
                '<p align="center">A</p><td>B</td>',
                'javascript',
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

    /**
     * HTML as the admin rich text editor (Tiptap) emits it must pass unchanged.
     *
     * @return array<string, array{0: string}>
     */
    public static function editorOutput(): array
    {
        return [
            'bold, italic, underline, strike' => ['<p><strong>B</strong> <em>I</em> <u>U</u> <s>S</s></p>'],
            'alignment' => ['<p style="text-align: center">C</p><p style="text-align: justify">J</p><h2 style="text-align: right">R</h2>'],
            'lists' => ['<ul><li><p>One</p></li></ul><ol><li><p>Two</p></li></ol>'],
            'link' => ['<p><a href="https://example.org/x">Site</a> <a href="mailto:a@example.org">Mail</a></p>'],
            'hex colour, size and every editor font' => ['<p><span style="color: #b91c1c; font-size: 20px; font-family: Georgia, serif">x</span></p>'],
            'rgb colour' => ['<p><span style="color: rgb(185, 28, 28)">x</span></p>'],
            'quoted font stack' => ["<p><span style=\"font-family: 'Times New Roman', Times, serif\">x</span></p>"],
            'courier and trebuchet' => ["<p><span style=\"font-family: 'Courier New', Courier, monospace\">a</span><span style=\"font-family: 'Trebuchet MS', Helvetica, sans-serif\">b</span></p>"],
        ];
    }

    #[Test]
    #[DataProvider('editorOutput')]
    public function editor_output_passes_unchanged(string $html): void
    {
        $this->assertSame($html, (new HtmlSanitizer)->sanitize($html));
    }

    #[Test]
    public function every_editor_font_stack_is_kept_and_normalised(): void
    {
        foreach (HtmlSanitizer::EDITOR_FONT_STACKS as $stack) {
            $loose = strtolower(str_replace(["'", ', '], ['"', ','], $stack));
            $output = (new HtmlSanitizer)->sanitize('<span style="font-family: '.htmlspecialchars($loose).'">x</span>');

            $this->assertSame('<span style="font-family: '.$stack.'">x</span>', $output);
        }
    }

    #[Test]
    public function the_font_list_matches_the_editor(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/components/admin/richText.ts'));
        preg_match_all('/stack: (\'[^\']+\'|"[^"]+")/', $source, $matches);

        $this->assertSame(HtmlSanitizer::EDITOR_FONT_STACKS, array_map(fn (string $quoted): string => substr($quoted, 1, -1), $matches[1]));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function styleValues(): array
    {
        return [
            'short hex' => ['color: #F00', 'color: #f00'],
            'hex with alpha' => ['color: #ff000080', 'color: #ff000080'],
            'rgba' => ['color: rgba(0, 0, 0, 0.5)', 'color: rgba(0, 0, 0, 0.5)'],
            'hsl' => ['color: hsl(120deg, 50%, 40%)', 'color: hsl(120deg, 50%, 40%)'],
            'named colour' => ['color: DarkRed', 'color: darkred'],
            'size in pt' => ['font-size: 12pt', 'font-size: 12pt'],
            'size in em' => ['font-size: 1.25em', 'font-size: 1.25em'],
            'size in rem' => ['font-size: 2rem', 'font-size: 2rem'],
            'size in percent' => ['font-size: 150%', 'font-size: 150%'],
            'size keyword' => ['font-size: x-large', 'font-size: x-large'],
            'size too small' => ['font-size: 2px', ''],
            'size too big' => ['font-size: 500px', ''],
            'em too big' => ['font-size: 40em', ''],
            'percent too small' => ['font-size: 10%', ''],
            'negative size' => ['font-size: -10px', ''],
            'unknown size unit' => ['font-size: 12vw', ''],
            'font not offered by the editor' => ['font-family: Comic Sans MS, cursive', ''],
            'web font' => ['font-family: Arial, url(https://evil.example/f.woff)', ''],
            'colour expression' => ['color: expression(alert(1))', ''],
            'colour url' => ['color: url(javascript:alert(1))', ''],
            'colour javascript' => ['color: javascript:alert(1)', ''],
            'colour with comment' => ['color: red/**/', ''],
            'comment inside the property' => ['col/**/or: red', ''],
            'comment hiding expression' => ['color: /**/expression(alert(1))', ''],
            'css escape' => ['color: \72 ed', ''],
            'escaped expression' => ['font-size: \65 xpression(alert(1))', ''],
            'behavior' => ['behavior: url(x.htc); color: red', 'color: red'],
            '-moz-binding' => ['-moz-binding: url(x.xml#xss)', ''],
            'position' => ['position: fixed; top: 0; color: red', 'color: red'],
            'background image' => ['background: url(https://evil.example/x.png)', ''],
            'background colour is not offered' => ['background-color: yellow', ''],
            'import' => ['@import url(https://evil.example/x.css); color: red', 'color: red'],
            'important' => ['color: red !important', ''],
            'rgb with letters' => ['color: rgb(e,x,p)', ''],
            'hsl with url' => ['color: hsl(url(x))', ''],
            'very long name' => ['color: '.str_repeat('a', 40), ''],
            'semicolon split' => ['color: red;font-size: 20px;;', 'color: red; font-size: 20px'],
            'quoted font smuggling a second declaration' => ['font-family: "Arial;position:fixed"', ''],
        ];
    }

    #[Test]
    #[DataProvider('styleValues')]
    public function it_filters_editor_style_values(string $style, string $expected): void
    {
        $output = (new HtmlSanitizer)->sanitize('<span style="'.htmlspecialchars($style).'">x</span>');
        $expectedHtml = $expected === '' ? '<span>x</span>' : '<span style="'.htmlspecialchars($expected).'">x</span>';

        $this->assertSame($expectedHtml, $output);
    }

    #[Test]
    public function entity_encoded_and_broken_markup_cannot_smuggle_active_content(): void
    {
        $sanitizer = new HtmlSanitizer;

        $encoded = $sanitizer->sanitize('<span style="color&#58; red; background&#58;url(&#106;avascript:alert(1))">x</span>');
        $this->assertSame('<span style="color: red">x</span>', $encoded);

        $entityExpression = $sanitizer->sanitize('<span style="color: &#101;xpression(alert(1))">x</span>');
        $this->assertSame('<span>x</span>', $entityExpression);

        $unclosed = $sanitizer->sanitize('<span style="color:red>x</span><img src=x onerror=alert(1)>');
        $this->assertStringNotContainsStringIgnoringCase('onerror', $unclosed);
        $this->assertStringNotContainsString('style="color:red>', $unclosed);

        $unquoted = $sanitizer->sanitize('<p style=color:red;position:fixed onclick=alert(1)>t</p>');
        $this->assertSame('<p style="color: red">t</p>', $unquoted);

        $javascriptLink = $sanitizer->sanitize('<a href="jav&#x61;script&#58;alert(1)" style="color: red">x</a>');
        $this->assertSame('<a style="color: red">x</a>', $javascriptLink);

        $html5Entity = $sanitizer->sanitize('<a href="javascript&colon;alert(1)">x</a>');
        $this->assertSame('<a href="javascript&amp;colon;alert(1)">x</a>', $html5Entity, 'libxml leaves the HTML5 entity as text, so the browser sees a harmless relative URL');
    }

    #[Test]
    public function it_keeps_utf8_text_intact(): void
    {
        $output = (new HtmlSanitizer)->sanitize('<p>Café – 東京</p>');

        $this->assertSame('<p>Café – 東京</p>', $output);
    }
}
