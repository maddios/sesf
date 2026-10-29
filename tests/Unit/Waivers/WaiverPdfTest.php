<?php

namespace Tests\Unit\Waivers;

use App\Models\CarShow;
use App\Services\Waivers\SignedWaiverData;
use App\Services\Waivers\WaiverPdf;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Fixtures\images\ImageFixtures as F;
use Tests\TestCase;

/** PDF HTML, dompdf rendering and atomic storage in a temp dir. */
class WaiverPdfTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/sesf-waiver-pdf-'.bin2hex(random_bytes(6));
        config(['waivers.storage_dir' => $this->dir, 'app.timezone' => 'America/New_York']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    private function show(): CarShow
    {
        return (new CarShow)->forceFill(['id' => 5, 'name' => 'Fall Fest', 'date_start' => '2026-10-01', 'date_end' => '2026-10-03']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function data(array $overrides = []): SignedWaiverData
    {
        return SignedWaiverData::read(array_merge([
            'format' => 'sesf-2', 'first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@example.test',
            'signature_png_base64' => base64_encode(F::png(40, 10, 'black')),
        ], $overrides));
    }

    #[Test]
    public function the_signed_html_escapes_signer_values_and_embeds_the_signature(): void
    {
        $html = (new WaiverPdf)->signedHtml($this->show(), $this->data([
            'first_name' => '<img src=x onerror=alert(1)>', 'guardian_name' => '"Bob" & <b>',
        ]), Carbon::parse('2026-10-25 14:00:00', 'UTC'));

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('&quot;Bob&quot; &amp; &lt;b&gt;', $html);
        $this->assertStringContainsString('src="data:image/png;base64,', $html);
        $this->assertStringContainsString('Signed electronically:</strong> 2026-10-25 10:00:00 EDT', $html);
        $this->assertStringContainsString('FALL FEST EVENT INFECTIOUS DISEASE ACKNOWLEDGEMENT AND WAIVER – FALL 2026', $html);
    }

    #[Test]
    public function it_renders_a_multi_page_pdf(): void
    {
        $pdf = new WaiverPdf;
        $bytes = $pdf->render($pdf->signedHtml($this->show(), $this->data(), Carbon::now()));

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThanOrEqual(3, preg_match_all('#/Type\s*/Page\b#', $bytes));
        $this->assertStringContainsString('/Subtype /Image', $bytes);
    }

    #[Test]
    public function the_blank_form_renders_without_signer_fields(): void
    {
        $pdf = new WaiverPdf;
        $html = $pdf->blankHtml($this->show());

        $this->assertStringContainsString('Printed name (parent/guardian):', $html);
        $this->assertStringNotContainsString('Signed electronically', $html);
        $this->assertStringStartsWith('%PDF-', $pdf->render($html));
    }

    #[Test]
    public function remote_resources_are_never_fetched(): void
    {
        $pdf = new WaiverPdf;
        // Would hang or fail under Http::preventStrayRequests if dompdf tried to load it; it must be ignored.
        $bytes = $pdf->render('<html><body><img src="http://127.0.0.1:9/x.png"><img src="file:///etc/passwd">ok</body></html>');

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringNotContainsString('/Subtype /Image', $bytes);
    }

    #[Test]
    public function store_writes_atomically_with_mode_0640(): void
    {
        $pdf = new WaiverPdf;
        $path = $pdf->store('0123456789abcdef', '%PDF-1.7 test');

        $this->assertSame($this->dir.'/0123456789abcdef.pdf', $path);
        $this->assertSame('%PDF-1.7 test', file_get_contents($path));
        $this->assertSame(0640, fileperms($path) & 0777);
        $this->assertSame(['0123456789abcdef.pdf'], array_values(array_diff((array) scandir($this->dir), ['.', '..'])));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badIds(): array
    {
        return [
            'traversal' => ['../../etc/passwd'],
            'encoded traversal' => ['..%2f..%2fx'],
            'slash' => ['abcd/efgh12'],
            'null byte' => ["abcdef12\0.php"],
            'too short' => ['abc'],
            'too long' => [str_repeat('a', 33)],
            'dot' => ['abcdefgh.pdf'],
        ];
    }

    #[Test]
    #[DataProvider('badIds')]
    public function the_path_rejects_unsafe_ids(string $id): void
    {
        $this->expectException(RuntimeException::class);
        (new WaiverPdf)->path($id);
    }
}
