<?php

namespace Tests\Unit\Waivers;

use App\Services\Waivers\InvalidSignature;
use App\Services\Waivers\SignatureImage;
use Imagick;
use ImagickPixel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\images\ImageFixtures as F;
use Tests\TestCase;

/** The signature validation matrix: only a real, bounded PNG data URL survives, re-encoded. */
class SignatureImageTest extends TestCase
{
    private function dataUrl(string $bytes, string $type = 'image/png'): string
    {
        return 'data:'.$type.';base64,'.base64_encode($bytes);
    }

    private function reject(mixed $input, string $reason): void
    {
        try {
            (new SignatureImage)->fromDataUrl($input);
            $this->fail('Expected the signature to be rejected: '.$reason);
        } catch (InvalidSignature $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    #[Test]
    public function a_real_png_is_re_encoded_without_metadata(): void
    {
        $image = new Imagick;
        $image->newImage(300, 100, new ImagickPixel('white'));
        $image->setImageFormat('PNG');
        $image->setImageProperty('comment', 'SECRET_COMMENT');
        $image->setImageProperty('Software', 'SECRET_SOFTWARE');
        $bytes = $image->getImageBlob();
        $this->assertStringContainsString('SECRET_COMMENT', $bytes);

        $out = base64_decode((new SignatureImage)->fromDataUrl($this->dataUrl($bytes)), true);

        $this->assertIsString($out);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $out);
        $this->assertStringNotContainsString('SECRET_COMMENT', $out);
        $this->assertStringNotContainsString('SECRET_SOFTWARE', $out);
        $this->assertSame([300, 100], array_slice((array) getimagesizefromstring($out), 0, 2));
    }

    #[Test]
    public function a_polyglot_png_loses_its_trailing_payload(): void
    {
        $out = base64_decode((new SignatureImage)->fromDataUrl($this->dataUrl(F::pngWithPhpPayload())), true);

        $this->assertIsString($out);
        $this->assertStringNotContainsString('PAYLOAD_MARKER', $out);
        $this->assertStringNotContainsString('<?php', $out);
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function rejected(): array
    {
        return [
            'missing' => [null, 'missing'],
            'empty string' => ['', 'missing'],
            'array' => [['x'], 'missing'],
            'bare base64 (no data url)' => [base64_encode(F::png(4, 4)), 'not_png'],
            'svg data url' => ['data:image/svg+xml;base64,'.base64_encode(F::svgWithScript()), 'not_png'],
            'svg bytes labelled png' => ['data:image/png;base64,'.base64_encode(F::svgWithScript()), 'not_png'],
            'jpeg labelled png' => ['data:image/png;base64,'.base64_encode(F::jpeg()), 'not_png'],
            'jpeg data url' => ['data:image/jpeg;base64,'.base64_encode(F::jpeg()), 'not_png'],
            'text labelled png' => ['data:image/png;base64,'.base64_encode('hello world, not an image'), 'not_png'],
            'invalid base64' => ['data:image/png;base64,@@@not*base64!!', 'unreadable'],
            'empty payload' => ['data:image/png;base64,', 'missing'],
            'truncated png' => ['data:image/png;base64,'.base64_encode(F::truncatedPng()), 'unreadable'],
        ];
    }

    #[Test]
    #[DataProvider('rejected')]
    public function invalid_signatures_are_rejected(mixed $input, string $reason): void
    {
        $this->reject($input, $reason);
    }

    #[Test]
    public function huge_dimensions_are_rejected_before_decoding(): void
    {
        $this->reject($this->dataUrl(F::png(2001, 10)), 'dimensions');
        $this->reject($this->dataUrl(F::png(10, 801)), 'dimensions');
    }

    #[Test]
    public function an_oversized_base64_payload_is_rejected(): void
    {
        config(['sesf.waiver_signature_base64_max_chars' => 1000]);

        $this->reject('data:image/png;base64,'.str_repeat('A', 1001), 'too_large');
    }

    #[Test]
    public function a_png_header_claiming_huge_dimensions_is_rejected(): void
    {
        // Valid 1x1 PNG with the IHDR width/height patched to 100000 x 100000 (a decompression bomb header).
        $bytes = F::png(1, 1);
        $patched = substr($bytes, 0, 16).pack('NN', 100000, 100000).substr($bytes, 24);

        $this->expectException(InvalidSignature::class);
        (new SignatureImage)->fromDataUrl($this->dataUrl($patched));
    }
}
