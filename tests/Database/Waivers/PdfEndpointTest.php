<?php

namespace Tests\Database\Waivers;

use App\Models\User;
use App\Models\Waiver;
use App\Services\Waivers\SignPayload;
use App\Services\Waivers\WaiverSignService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** GET waiver-pdf?waiverId=: admin only, inline from storage, regenerated when missing. */
class PdfEndpointTest extends WaiverTestCase
{
    private function signedWaiver(): Waiver
    {
        $service = $this->app->make(WaiverSignService::class);
        $waiver = $service->createPending($this->registration());

        return $service->sign($waiver, new SignPayload('Ada', 'Admin', 'ada@example.test', 'draw', $this->signatureUrl()), null, null);
    }

    #[Test]
    public function anonymous_is_401_and_members_are_403(): void
    {
        $waiver = $this->signedWaiver();

        $this->getJson('/waiver-pdf?waiverId='.$waiver->waiver_id)->assertStatus(401);
        $this->actingAs(User::factory()->create())->getJson('/waiver-pdf?waiverId='.$waiver->waiver_id)->assertStatus(403);
        // A fresh session: AuthenticateSession would otherwise log out the second acting user.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs(User::factory()->judge()->create())->getJson('/waiver-pdf?waiverId='.$waiver->waiver_id)->assertStatus(403);
    }

    #[Test]
    public function admins_get_the_pdf_inline(): void
    {
        $waiver = $this->signedWaiver();

        $response = $this->actingAs(User::factory()->admin()->create())->get('/waiver-pdf?waiverId='.$waiver->waiver_id);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'inline; filename="waiver-'.$waiver->waiver_id.'.pdf"');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $file = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $file);
        $this->assertStringStartsWith('%PDF-', (string) file_get_contents($file->getFile()->getPathname()));
    }

    #[Test]
    public function a_missing_file_is_regenerated_for_admins(): void
    {
        $waiver = $this->signedWaiver();
        unlink($this->pdfDir.'/'.$waiver->waiver_id.'.pdf');

        $this->actingAs(User::factory()->admin()->create())->get('/waiver-pdf?waiverId='.$waiver->waiver_id)->assertOk();

        $this->assertFileExists($this->pdfDir.'/'.$waiver->waiver_id.'.pdf');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badIds(): array
    {
        return [
            'traversal' => ['../../../../etc/passwd'],
            'encoded traversal' => ['..%2F..%2F.env'],
            'absolute' => ['/etc/passwd'],
            'null byte' => ['abcdef0123456789%00'],
            'array' => [''],
            'unknown' => ['ffffffffffffffff'],
            'too long' => [str_repeat('a', 40)],
        ];
    }

    #[Test]
    #[DataProvider('badIds')]
    public function unsafe_or_unknown_ids_are_404(string $id): void
    {
        $this->actingAs(User::factory()->admin()->create())->getJson('/waiver-pdf?waiverId='.$id)
            ->assertNotFound()->assertExactJson(['success' => false]);
    }

    #[Test]
    public function an_array_parameter_is_404(): void
    {
        $this->actingAs(User::factory()->admin()->create())->getJson('/waiver-pdf?waiverId[]=abc')->assertNotFound();
    }

    #[Test]
    public function an_unsigned_waiver_has_no_pdf(): void
    {
        $waiver = Waiver::factory()->create(['show_registration_id' => $this->registration()->id]);
        Waiver::query()->whereKey($waiver->id)->update(['waiver_id' => '0000111122223333']);

        $this->actingAs(User::factory()->admin()->create())->getJson('/waiver-pdf?waiverId=0000111122223333')->assertNotFound();
    }
}
