<?php

namespace Tests\Database\Waivers;

use App\Models\Waiver;
use App\Services\Waivers\InvalidSignature;
use App\Services\Waivers\SignedWaiverData;
use App\Services\Waivers\SignPayload;
use App\Services\Waivers\WaiverAlreadySigned;
use App\Services\Waivers\WaiverNotFound;
use App\Services\Waivers\WaiverPdf;
use App\Services\Waivers\WaiverSignService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Fixtures\images\ImageFixtures as F;

/** Pending creation, signing, idempotency and PDF (re)generation through WaiverSignService. */
class WaiverSignServiceTest extends WaiverTestCase
{
    /** @var list<string> */
    public static array $renderedHtml = [];

    public static bool $failRender = false;

    protected function setUp(): void
    {
        parent::setUp();
        self::$renderedHtml = [];
        self::$failRender = false;

        // Records the HTML dompdf receives (text in a subset-font PDF is not searchable).
        $this->app->bind(WaiverPdf::class, fn () => new class extends WaiverPdf
        {
            public function render(string $html): string
            {
                WaiverSignServiceTest::$renderedHtml[] = $html;
                if (WaiverSignServiceTest::$failRender) {
                    throw new RuntimeException('render failed');
                }

                return parent::render($html);
            }
        });
    }

    private function service(): WaiverSignService
    {
        return $this->app->make(WaiverSignService::class);
    }

    private function signPayload(string $first = 'Sam', string $last = 'Signer', ?string $sig = null): SignPayload
    {
        return new SignPayload($first, $last, 'sam@example.test', 'draw', $sig ?? $this->signatureUrl());
    }

    #[Test]
    public function pending_waivers_get_unique_tracking_ids_and_both_fk_directions(): void
    {
        $registration = $this->registration();
        $guest = $this->guest($registration);

        $own = $this->service()->createPending($registration);
        $theirs = $this->service()->createPending($registration, $guest);

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $this->tracking($own));
        $this->assertNotSame($this->tracking($own), $this->tracking($theirs));
        $this->assertSame($registration->id, $own->show_registration_id);
        $this->assertNull($own->guest_id);
        $this->assertSame($own->id, $registration->fresh()?->waiver_id);
        $this->assertSame($guest->id, $theirs->guest_id);
        $this->assertNull($theirs->show_registration_id);
        $this->assertSame($theirs->id, $guest->fresh()?->waiver_id);
        $this->assertTrue($own->isPending());
        $this->assertFalse($own->has_signed);
    }

    #[Test]
    public function ensure_pending_reuses_the_existing_waiver_and_rejects_foreign_guests(): void
    {
        $registration = $this->registration();
        $first = $this->service()->ensurePending($registration);

        $this->assertSame($first->id, $this->service()->ensurePending($registration->fresh() ?? $registration)->id);
        $this->assertSame(1, Waiver::query()->where('show_registration_id', $registration->id)->count());

        $otherGuest = $this->guest($this->registration());
        $this->expectException(WaiverNotFound::class);
        $this->service()->ensurePending($registration, $otherGuest);
    }

    #[Test]
    public function signing_stores_the_new_shape_flags_the_participant_and_writes_the_pdf(): void
    {
        $registration = $this->registration();
        $guest = $this->guest($registration);
        $waiver = $this->service()->createPending($registration, $guest);

        $signed = $this->service()->sign($waiver, new SignPayload(
            'Kid', 'Guest', 'kid@example.test', 'type', $this->signatureUrl(), 'Kid Guest', true, 'Pat', 'Guest',
            ['lat' => 28.5, 'lng' => -81.4, 'accuracy' => 12.0],
        ), '203.0.113.9', str_repeat('UA', 300));

        $this->assertTrue($signed->has_signed);
        $this->assertTrue($signed->has_pdf);
        $this->assertNotNull($signed->signed_at);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', (string) $signed->waiver_id);
        $this->assertTrue($guest->fresh()?->has_signed);
        $this->assertFalse($registration->fresh()?->has_signed);

        $data = (array) $signed->waiver_data;
        $this->assertSame(SignedWaiverData::FORMAT_CURRENT, $data['format']);
        $this->assertSame(['Kid', 'Guest', 'kid@example.test', 'type', 'Kid Guest', true, 'Pat Guest'], [
            $data['first_name'], $data['last_name'], $data['email'], $data['signature_mode'], $data['signature_text'], $data['is_minor'], $data['guardian_name'],
        ]);
        $this->assertSame(['lat' => 28.5, 'lng' => -81.4, 'accuracy' => 12], $data['geolocation']);
        $this->assertSame(255, mb_strlen($data['user_agent']));
        $this->assertSame(WaiverSignService::ipHash('203.0.113.9'), $data['ip_hash']);
        $this->assertStringNotContainsString('203.0.113.9', (string) json_encode($data));
        $this->assertSame($registration->show_id, $data['show_id']);

        $path = $this->pdfDir.'/'.$signed->waiver_id.'.pdf';
        $this->assertFileExists($path);
        $this->assertStringStartsWith('%PDF-', (string) file_get_contents($path));
        $this->assertSame(0640, fileperms($path) & 0777);
        $this->assertCount(1, self::$renderedHtml);
        $this->assertStringContainsString('>Kid</div>', self::$renderedHtml[0]);
        $this->assertStringContainsString('Parent/guardian name:</strong> Pat Guest', self::$renderedHtml[0]);
    }

    #[Test]
    public function a_signed_waiver_cannot_be_signed_again(): void
    {
        $waiver = $this->service()->createPending($this->registration());
        $signed = $this->service()->sign($waiver, $this->signPayload(), null, null);
        $before = [$signed->waiver_id, $signed->waiver_data, (string) $signed->signed_at];

        try {
            $this->service()->sign($signed, $this->signPayload('Mallory'), null, null);
            $this->fail('second sign must throw');
        } catch (WaiverAlreadySigned) {
        }

        $after = $signed->fresh();
        $this->assertSame($before, [$after?->waiver_id, $after?->waiver_data, (string) $after?->signed_at]);
    }

    #[Test]
    public function a_racing_second_sign_is_stopped_by_the_row_lock_check(): void
    {
        $waiver = $this->service()->createPending($this->registration());
        // Both "requests" loaded the row before either signed.
        $stale = Waiver::query()->findOrFail($waiver->id);

        $queries = [];
        DB::listen(function (QueryExecuted $q) use (&$queries): void {
            $queries[] = strtolower($q->sql);
        });

        $this->service()->sign($waiver, $this->signPayload('First'), null, null);

        $this->assertNotEmpty(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `waiver`') && str_ends_with($sql, 'for update')));
        $this->assertFalse($stale->has_signed);
        $this->expectException(WaiverAlreadySigned::class);
        $this->service()->sign($stale, $this->signPayload('Second'), null, null);
    }

    #[Test]
    public function an_invalid_signature_leaves_the_waiver_unsigned(): void
    {
        $waiver = $this->service()->createPending($this->registration());

        try {
            $this->service()->sign($waiver, $this->signPayload(sig: 'data:image/png;base64,'.base64_encode(F::svgWithScript())), null, null);
            $this->fail('expected rejection');
        } catch (InvalidSignature) {
        }

        $this->assertFalse($waiver->fresh()?->has_signed);
        $this->assertNull(Waiver::query()->findOrFail($waiver->id)->waiver_id);
    }

    #[Test]
    public function a_pdf_failure_keeps_the_signature_and_the_admin_path_regenerates_it(): void
    {
        self::$failRender = true;
        $waiver = $this->service()->createPending($this->registration());

        $signed = $this->service()->sign($waiver, $this->signPayload(), null, null);

        $this->assertTrue($signed->has_signed);
        $this->assertFalse($signed->has_pdf);

        self::$failRender = false;
        $path = $this->service()->pdfPathForAdmin($signed);
        $this->assertSame($this->pdfDir.'/'.$signed->waiver_id.'.pdf', $path);
        $this->assertTrue($signed->fresh()?->has_pdf);
    }

    #[Test]
    public function legacy_native_rows_are_regenerated_with_their_signature(): void
    {
        $registration = $this->registration();
        $waiver = Waiver::factory()->create([
            'show_registration_id' => $registration->id,
            'waiver_id' => 'abcdef0123456789',
            'has_signed' => true,
            'signed_at' => now(),
            'waiver_data' => [
                'internal_version' => 1, 'show_id' => $registration->show_id, 'first_name' => 'Legacy', 'last_name' => 'Native',
                'email' => 'ln@example.test', 'signing_for_minor' => false, 'signature_mode' => 'draw',
                'signature_image_base64' => base64_encode(F::png(50, 20)),
            ],
        ]);

        $this->assertTrue($this->service()->writePdf($waiver));
        $this->assertFileExists($this->pdfDir.'/abcdef0123456789.pdf');
        $this->assertStringContainsString('>Legacy</div>', self::$renderedHtml[0]);
        $this->assertStringContainsString('data:image/png;base64,', self::$renderedHtml[0]);
    }

    #[Test]
    public function legacy_third_party_rows_are_regenerated_with_a_labelled_reconstruction(): void
    {
        $registration = $this->registration();
        $waiver = Waiver::factory()->create([
            'show_registration_id' => $registration->id,
            'waiver_id' => 'WfAbc123Xyz',
            'has_signed' => true,
            'signed_at' => now(),
            'waiver_data' => ['result' => true, 'msg' => '', 'data' => [
                'id' => 'WfAbc123Xyz', 'template_title' => 'SESF 2019', 'geolocation' => ['latitude' => 1, 'longitude' => 2],
                'data' => [['type' => 'name_field', 'first_name' => 'Third', 'last_name' => 'Party'], ['type' => 'email_field', 'value' => 'tp@example.test']],
            ]],
        ]);

        $this->assertTrue($this->service()->writePdf($waiver));
        $html = self::$renderedHtml[0];
        $this->assertStringContainsString('>Third</div>', $html);
        $this->assertStringContainsString('WaiverForever (record WfAbc123Xyz), template &quot;SESF 2019&quot;', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    #[Test]
    public function rows_that_cannot_be_reproduced_faithfully_are_not_regenerated(): void
    {
        $registration = $this->registration();
        $unknown = Waiver::factory()->signed()->create(['show_registration_id' => $registration->id]);
        $badImage = Waiver::factory()->create([
            'show_registration_id' => $registration->id, 'waiver_id' => '1111222233334444', 'has_signed' => true,
            'waiver_data' => ['internal_version' => 1, 'show_id' => $registration->show_id, 'signature_image_base64' => base64_encode(F::svgWithScript())],
        ]);
        $unsigned = Waiver::factory()->create(['show_registration_id' => $registration->id]);

        $this->assertFalse($this->service()->writePdf($unknown));
        $this->assertFalse($this->service()->writePdf($badImage));
        $this->assertNull($this->service()->pdfPathForAdmin($unsigned));
        $this->assertSame([], self::$renderedHtml);
    }

    #[Test]
    public function a_legacy_public_copy_is_moved_into_storage(): void
    {
        $waiver = Waiver::factory()->create([
            'show_registration_id' => $this->registration()->id, 'waiver_id' => 'LegacyPdf0001', 'has_signed' => true, 'waiver_data' => null,
        ]);
        mkdir($this->legacyDir, 0755, true);
        file_put_contents($this->legacyDir.'/LegacyPdf0001.pdf', '%PDF-1.4 legacy');

        $path = $this->service()->pdfPathForAdmin($waiver);

        $this->assertSame($this->pdfDir.'/LegacyPdf0001.pdf', $path);
        $this->assertSame('%PDF-1.4 legacy', file_get_contents((string) $path));
    }

    #[Test]
    public function the_context_reports_missing_owners_as_not_found(): void
    {
        $show = $this->show();
        $adhoc = $this->service()->createAdhoc($show);
        $this->assertSame($show->id, $this->service()->context($adhoc)->show->id);

        $orphan = Waiver::factory()->create(['show_registration_id' => null, 'guest_id' => null, 'adhoc_show_id' => 999999]);
        $this->expectException(WaiverNotFound::class);
        $this->service()->context($orphan);
    }

    #[Test]
    public function stale_unsigned_walk_in_waivers_are_pruned(): void
    {
        $show = $this->show();
        $old = $this->service()->createAdhoc($show);
        Waiver::query()->whereKey($old->id)->update(['created_at' => now()->subDays(10)]);
        $fresh = $this->service()->createAdhoc($show);

        $this->assertSame(1, $this->service()->pruneStaleAdhoc(7));
        $this->assertNull(Waiver::query()->find($old->id));
        $this->assertNotNull(Waiver::query()->find($fresh->id));
    }
}
