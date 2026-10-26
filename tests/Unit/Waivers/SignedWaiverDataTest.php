<?php

namespace Tests\Unit\Waivers;

use App\Services\Waivers\SignedWaiverData;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** waiver_data is read in the current shape and both legacy shapes. */
class SignedWaiverDataTest extends TestCase
{
    #[Test]
    public function it_reads_the_current_shape(): void
    {
        $data = SignedWaiverData::read([
            'format' => 'sesf-2', 'show_id' => 7, 'first_name' => 'Ann', 'last_name' => 'Lee',
            'email' => 'ann@example.test', 'signature_png_base64' => 'AAAA', 'signature_mode' => 'type',
            'signature_text' => 'Ann Lee', 'is_minor' => true, 'guardian_name' => 'Bob Lee',
        ]);

        $this->assertSame(SignedWaiverData::FORMAT_CURRENT, $data->format);
        $this->assertSame(['Ann', 'Lee', 'ann@example.test', 'Bob Lee', 'AAAA', 'Ann Lee', 7], [
            $data->firstName, $data->lastName, $data->email, $data->guardianName, $data->signaturePngBase64, $data->typedText, $data->showId,
        ]);
    }

    #[Test]
    public function it_reads_the_legacy_native_shape(): void
    {
        $data = SignedWaiverData::read([
            'internal_version' => 1, 'show_id' => '12', 'first_name' => 'Kid', 'last_name' => 'Doe', 'email' => '',
            'signing_for_minor' => true, 'adult_first_name' => 'Pat', 'adult_last_name' => 'Doe',
            'signature_mode' => 'draw', 'signature_image_base64' => 'BBBB',
        ]);

        $this->assertSame(SignedWaiverData::FORMAT_LEGACY_NATIVE, $data->format);
        $this->assertSame('Pat Doe', $data->guardianName);
        $this->assertSame('BBBB', $data->signaturePngBase64);
        $this->assertSame(12, $data->showId);
    }

    #[Test]
    public function the_guardian_is_ignored_when_the_legacy_row_was_not_for_a_minor(): void
    {
        $data = SignedWaiverData::read(['internal_version' => 1, 'signing_for_minor' => false, 'adult_first_name' => 'X', 'signature_image_base64' => 'B']);

        $this->assertSame('', $data->guardianName);
    }

    #[Test]
    public function it_reads_the_legacy_third_party_shape(): void
    {
        $data = SignedWaiverData::read([
            'result' => true,
            'msg' => '',
            'data' => [
                'id' => 'abc123XYZ',
                'tracking_id' => str_repeat('a', 32),
                'template_title' => 'Sharkfest 2019',
                'geolocation' => ['latitude' => 28.5, 'longitude' => -81.3],
                'data' => [
                    ['type' => 'name_field', 'first_name' => 'Old', 'last_name' => 'Timer'],
                    ['type' => 'email_field', 'value' => 'old@example.test'],
                ],
            ],
        ]);

        $this->assertSame(SignedWaiverData::FORMAT_LEGACY_THIRD_PARTY, $data->format);
        $this->assertSame(['Old', 'Timer', 'old@example.test', 'Sharkfest 2019'], [$data->firstName, $data->lastName, $data->email, $data->templateTitle]);
        $this->assertNull($data->signaturePngBase64);
    }

    #[Test]
    public function unknown_shapes_are_not_known(): void
    {
        $this->assertFalse(SignedWaiverData::read(null)->isKnown());
        $this->assertFalse(SignedWaiverData::read(['signature' => 'x'])->isKnown());
        $this->assertFalse(SignedWaiverData::read(['result' => true, 'data' => 'nope'])->isKnown());
    }
}
