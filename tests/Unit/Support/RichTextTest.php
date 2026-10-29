<?php

namespace Tests\Unit\Support;

use App\Support\HtmlSanitizer;
use App\Support\RichText;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RichTextTest extends TestCase
{
    private function richText(): RichText
    {
        return new RichText(new HtmlSanitizer);
    }

    #[Test]
    public function plain_text_is_escaped_exactly_as_the_legacy_pages_printed_it(): void
    {
        $this->assertSame('Fish &amp; chips &lt;3 &amp;amp;', $this->richText()->html(" Fish & chips <3 &amp;\n"));
        $this->assertSame('', $this->richText()->html(null));
        $this->assertFalse(RichText::hasMarkup('a < b'));
    }

    #[Test]
    public function html_goes_through_the_sanitizer(): void
    {
        $this->assertSame(
            '<p><strong>Hi</strong> <span style="color: #b91c1c">red</span></p>',
            $this->richText()->html('<p onclick="x()"><strong>Hi</strong> <span style="color: #b91c1c; position: fixed">red</span></p><script>alert(1)</script>'),
        );
    }

    #[Test]
    public function plain_keeps_the_legacy_rule_for_text_and_spaces_html_blocks(): void
    {
        $this->assertSame("Line one\nLine two", RichText::plain("  Line one\nLine two  "));
        $this->assertSame('One Two & three Four', RichText::plain('<p>One</p><p>Two &amp; <strong>three</strong></p><ul><li>Four</li></ul>'));
        $this->assertSame('a b', RichText::plain('a<br>b'));
        $this->assertSame('x y', RichText::plain("<p>x&nbsp;\u{00A0}y</p>"));
    }
}
