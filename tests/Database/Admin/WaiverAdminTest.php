<?php

namespace Tests\Database\Admin;

use App\Jobs\Waivers\RegenerateWaiverPdfs;
use App\Jobs\Waivers\SendWaiverReminder;
use App\Jobs\Waivers\SendWaiverRequest;
use App\Mail\WaiverRequestMail;
use App\Models\Waiver;
use App\Services\Waivers\WaiverMailer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * admin-api/waivers: the per-event roster, walk-ins, and the queued request and reminder sends.
 */
class WaiverAdminTest extends AdminTestCase
{
    use RegistrationFixtures;

    private const BASE = '/admin-api/waivers';

    /**
     * @return array<string, array{string, int}>
     */
    public static function actors(): array
    {
        return [
            'anonymous' => ['anonymous', 401],
            'member' => ['member', 403],
            'judge' => ['judge', 403],
            'admin' => ['admin', 200],
        ];
    }

    #[Test]
    #[DataProvider('actors')]
    public function every_waiver_route_is_admin_only(string $who, int $status): void
    {
        Bus::fake();
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);
        $expect = fn (int $ok) => $status === 200 ? $ok : $status;

        $as()->getJson(self::BASE.'?show_id='.$show->id)->assertStatus($expect(200));
        $as()->getJson(self::BASE.'/walk-ins?show_id='.$show->id)->assertStatus($expect(200));
        $as()->postJson(self::BASE.'/request', ['show_id' => $show->id, 'people' => [['registration_id' => $registration->id]]])->assertStatus($expect(202));
        $as()->withHeaders(self::JSON)->get(self::BASE.'/open-sign?show_id='.$show->id.'&registration_id='.$registration->id)->assertStatus($expect(302));
        $as()->withHeaders(self::JSON)->get(self::BASE.'/blank-pdf?show_id='.$show->id)->assertStatus($expect(200));
        $as()->postJson(self::BASE.'/regenerate-pdfs', ['show_id' => $show->id])->assertStatus($expect(202));
        $as()->postJson(self::BASE.'/reminders', ['show_id' => $show->id])->assertStatus($expect(202));
    }

    #[Test]
    public function the_roster_unions_registrants_and_guests_with_their_status(): void
    {
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);
        $guest = $registration->guests()->firstOrFail();
        $waiver = Waiver::factory()->signed()->create(['show_registration_id' => $registration->id]);
        DB::table('show_registration')->where('id', $registration->id)->update(['waiver_id' => $waiver->id]);

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'?show_id='.$show->id)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['row_key' => 'registrant-'.$registration->id, 'status' => 'signed', 'can_request' => false])
            ->assertJsonFragment(['row_key' => 'guest-'.$guest->id, 'status' => 'not_started', 'can_request' => true]);
    }

    #[Test]
    public function the_roster_needs_a_known_show(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(self::BASE)->assertStatus(422)->assertJsonValidationErrors('show_id');
        $this->actingAs($admin)->getJson(self::BASE.'?show_id=999999')->assertStatus(422)->assertJsonValidationErrors('show_id');
        $this->actingAs($admin)->getJson(self::BASE.'?show_id=abc')->assertStatus(422)->assertJsonValidationErrors('show_id');
    }

    #[Test]
    public function sort_and_filter_are_whitelisted(): void
    {
        $show = $this->registrationShow();
        $this->registrationWithCarAndGuest($show);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(self::BASE.'?show_id='.$show->id.'&sort=last;drop table user')->assertStatus(422);
        $this->actingAs($admin)->getJson(self::BASE.'?show_id='.$show->id.'&filter[status][like]=sig')->assertStatus(422);
        $this->actingAs($admin)->getJson(self::BASE.'?show_id='.$show->id.'&filter[status]=signed&sort=email')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_walk_in_list_shows_signed_walk_ins_with_their_pdf(): void
    {
        $show = $this->registrationShow();
        $walkIn = Waiver::factory()->adhoc($show)->signed()->create([
            'waiver_data' => ['first_name' => 'Walk', 'last_name' => 'In', 'email' => 'walk@example.test'],
        ]);

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'/walk-ins?show_id='.$show->id)
            ->assertOk()
            ->assertJsonPath('data.0.first', 'Walk')
            ->assertJsonPath('data.0.pdf_url', '/waiver-pdf?'.http_build_query(['waiverId' => $walkIn->waiver_id]));
    }

    #[Test]
    public function a_request_is_queued_for_the_registrant_and_audited(): void
    {
        Bus::fake();
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);

        $this->actingAs($this->admin())
            ->postJson(self::BASE.'/request', ['show_id' => $show->id, 'people' => [['registration_id' => $registration->id]]])
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 1);

        Bus::assertDispatched(SendWaiverRequest::class, fn (SendWaiverRequest $job) => $job->registrationId === $registration->id && $job->guestId === null);
        $this->assertContains('waiver.request', array_column($this->auditEntries(), 'action'));
    }

    #[Test]
    public function a_request_for_a_guest_must_belong_to_the_registration(): void
    {
        Bus::fake();
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);
        $other = $this->registrationWithCarAndGuest($show);
        $otherGuest = $other->guests()->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson(self::BASE.'/request', ['show_id' => $show->id, 'people' => [['registration_id' => $registration->id, 'guest_id' => $otherGuest->id]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('people.0.guest_id');

        $otherShow = $this->registrationShow();
        $this->actingAs($this->admin())
            ->postJson(self::BASE.'/request', ['show_id' => $otherShow->id, 'people' => [['registration_id' => $registration->id]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('people.0.registration_id');

        Bus::assertNotDispatched(SendWaiverRequest::class);
    }

    #[Test]
    public function reminders_are_queued_once_per_incomplete_registration(): void
    {
        Bus::fake();
        $show = $this->registrationShow();
        $incomplete = $this->registrationWithCarAndGuest($show);

        $this->actingAs($this->admin())
            ->postJson(self::BASE.'/reminders', ['show_id' => $show->id])
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 1);

        Bus::assertDispatchedTimes(SendWaiverReminder::class, 1);
        Bus::assertDispatched(SendWaiverReminder::class, fn (SendWaiverReminder $job) => $job->registrationId === $incomplete->id && $job->showId === $show->id);
        $this->assertContains('waiver.reminders', array_column($this->auditEntries(), 'action'));
    }

    #[Test]
    public function the_queued_request_job_sends_one_email_and_skips_signed_waivers(): void
    {
        Mail::fake();
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);

        (new SendWaiverRequest($registration->id, null))->handle(app(WaiverMailer::class));
        Mail::assertSent(WaiverRequestMail::class, 1);

        $signed = Waiver::factory()->signed()->create(['show_registration_id' => $registration->id]);
        DB::table('show_registration')->where('id', $registration->id)->update(['waiver_id' => $signed->id]);
        (new SendWaiverRequest($registration->id, null))->handle(app(WaiverMailer::class));
        Mail::assertSent(WaiverRequestMail::class, 1);
    }

    #[Test]
    public function the_request_body_never_reaches_the_audit_log(): void
    {
        Bus::fake();
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);

        $this->actingAs($this->admin())->postJson(self::BASE.'/request', ['show_id' => $show->id, 'people' => [['registration_id' => $registration->id]]]);

        $this->assertStringNotContainsString('@', $this->auditText());
    }

    #[Test]
    public function the_checked_in_sort_puts_checked_in_people_first(): void
    {
        $show = $this->registrationShow();
        $late = $this->registrationWithCarAndGuest($show, ['invoice_id' => 9001]);
        $checkedIn = $this->registrationWithCarAndGuest($show, ['invoice_id' => 9002]);
        DB::table('show_registration')->where('id', $checkedIn->id)->update(['date_checked_in' => '2026-10-01 09:00:00']);

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'?show_id='.$show->id.'&sort_checked_in=1')
            ->assertOk()
            ->assertJsonPath('data.0.checked_in', true)
            ->assertJsonPath('data.1.checked_in', true)
            ->assertJsonPath('data.2.checked_in', false)
            ->assertJsonPath('data.2.registration_id', $late->id)
            ->assertJsonPath('data.3.checked_in', false);
    }

    #[Test]
    public function the_roster_carries_the_arrival_for_the_arriving_column(): void
    {
        $show = $this->registrationShow();
        $this->registrationWithCarAndGuest($show);

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'?show_id='.$show->id)
            ->assertOk()
            ->assertJsonPath('data.0.arrival', fn ($value) => str_contains((string) $value, 'morning'));
    }

    #[Test]
    public function open_sign_creates_the_pending_waiver_and_redirects_to_its_sign_page(): void
    {
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);

        $response = $this->actingAs($this->admin())
            ->get(self::BASE.'/open-sign?show_id='.$show->id.'&registration_id='.$registration->id);

        $response->assertRedirectContains('/waiver_sign/t/');
        $this->assertNotNull(DB::table('show_registration')->where('id', $registration->id)->value('waiver_id'));
    }

    #[Test]
    public function open_sign_refuses_a_registration_from_another_event(): void
    {
        $show = $this->registrationShow();
        $other = $this->registrationWithCarAndGuest($this->registrationShow());

        $this->actingAs($this->admin())
            ->withHeaders(self::JSON)
            ->get(self::BASE.'/open-sign?show_id='.$show->id.'&registration_id='.$other->id)
            ->assertStatus(404);
    }

    #[Test]
    public function the_blank_waiver_is_a_pdf(): void
    {
        $show = $this->registrationShow();

        $response = $this->actingAs($this->admin())->get(self::BASE.'/blank-pdf?show_id='.$show->id);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function regenerating_pdfs_is_queued_and_audited(): void
    {
        Bus::fake();
        $show = $this->registrationShow();

        $this->actingAs($this->admin())
            ->postJson(self::BASE.'/regenerate-pdfs', ['show_id' => $show->id])
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 1);

        Bus::assertDispatched(RegenerateWaiverPdfs::class, fn (RegenerateWaiverPdfs $job) => $job->showId === $show->id);
        $this->assertContains('waiver.pdf_regenerate', array_column($this->auditEntries(), 'action'));
    }
}
