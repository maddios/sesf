<?php

namespace Tests\Database\Admin;

use App\Jobs\Admin\SendOutreachMail;
use App\Jobs\Waivers\ThrottleWaiverMail;
use App\Mail\RegistrationPayLinkMail;
use App\Mail\WaiverReminderMail;
use App\Models\Car;
use App\Models\CarShow;
use App\Models\Shirt;
use App\Models\ShirtType;
use App\Models\ShowExtra;
use App\Models\ShowExtraRegistration;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Models\UserGuest;
use App\Models\Waiver;
use Database\Factories\CarBodyFactory;
use Database\Factories\ShirtTypeFactory;
use Database\Factories\ShowExtraFactory;
use Database\Factories\ShowRegistrationFactory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * admin-api/checkin: the event list, check-in and check-out (paid, waivers, override), waiver reminder, sign links,
 * pay-link email, and registering a member on site (legacy CheckinList), with validation and audit.
 */
class CheckinAdminTest extends AdminTestCase
{
    use RegistrationFixtures;

    private const BASE = '/admin-api/checkin';

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
    public function the_list_is_admin_only(string $who, int $status): void
    {
        $show = $this->registrationShow();
        $user = $this->actor($who);
        $request = $user === null ? $this : $this->actingAs($user);

        $request->getJson(self::BASE.'/registrations?show_id='.$show->id)->assertStatus($status);
    }

    #[Test]
    #[DataProvider('actors')]
    public function every_write_and_option_is_admin_only(string $who, int $status): void
    {
        $show = $this->registrationShow();
        $registration = $this->paidRegistration($show);
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);
        $expect = fn (int $ok) => $status === 200 ? $ok : $status;

        $as()->getJson(self::BASE."/shows/{$show->id}/registration-options")->assertStatus($expect(200));
        $as()->getJson(self::BASE."/users/{$registration->user_id}/garage?show_id={$show->id}")->assertStatus($expect(200));
        $as()->postJson(self::BASE."/registrations/{$registration->id}/check-in", ['override' => true])->assertStatus($expect(200));
        $as()->postJson(self::BASE."/registrations/{$registration->id}/check-out")->assertStatus($expect(200));
        $as()->postJson(self::BASE."/registrations/{$registration->id}/waiver-reminder")->assertStatus($expect(200));
        $as()->postJson(self::BASE."/registrations/{$registration->id}/waiver-sign-url")->assertStatus($expect(200));
        $as()->postJson(self::BASE."/registrations/{$registration->id}/pay-link-email")->assertStatus($expect(200));
    }

    #[Test]
    public function the_list_is_the_event_without_cancelled_registrations(): void
    {
        $show = $this->registrationShow();
        $active = $this->paidRegistration($show);
        ShowRegistrationFactory::new()->cancelled()->create(['show_id' => $show->id]);
        $other = CarShow::factory()->create();
        ShowRegistrationFactory::new()->create(['show_id' => $other->id]);

        $rows = $this->actingAs($this->admin())
            ->getJson(self::BASE.'/registrations?show_id='.$show->id)
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($active->id, $rows[0]['id']);
        $this->assertFalse($rows[0]['party_complete']);
        $this->assertSame(['primary', 'guest'], array_column($rows[0]['party'], 'role'));
        $this->assertArrayHasKey('shirt_lines', $rows[0]);
        $this->assertArrayHasKey('primary_email', $rows[0]);
    }

    #[Test]
    public function the_list_needs_an_event(): void
    {
        $this->actingAs($this->admin())->getJson(self::BASE.'/registrations')->assertStatus(422);
        $this->actingAs($this->admin())->getJson(self::BASE.'/registrations?show_id=999999')->assertNotFound();
    }

    #[Test]
    public function checking_in_a_paid_party_with_signed_waivers_sets_the_time_and_is_audited(): void
    {
        $show = $this->registrationShow();
        $registration = $this->signedRegistration($show);

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/registrations/{$registration->id}/check-in")
            ->assertOk()
            ->assertJsonPath('data.is_checked_in', true)
            ->assertJsonPath('data.party_complete', true);

        $registration->refresh();
        $this->assertTrue((bool) $registration->is_checked_in);
        $this->assertNotNull($registration->date_checked_in);
        $this->assertSame('registration.check_in', collect($this->auditEntries())->last()['action']);
    }

    #[Test]
    public function an_unpaid_or_unsigned_party_is_refused_with_the_reason(): void
    {
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/registrations/{$registration->id}/check-in")
            ->assertStatus(422)
            ->assertJsonPath('errors.registration.0', 'Cannot check in: registration is not marked paid and not everyone in the party has signed waivers.');

        $this->assertFalse((bool) $registration->refresh()->is_checked_in);
    }

    #[Test]
    public function an_admin_override_checks_in_an_unpaid_party_and_is_audited(): void
    {
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/registrations/{$registration->id}/check-in", ['override' => true])
            ->assertOk()
            ->assertJsonPath('data.is_checked_in', true);

        $this->assertSame(['override' => true], collect($this->auditEntries())->last()['extra']);
    }

    #[Test]
    public function checking_in_again_replaces_the_time_as_legacy_did(): void
    {
        $show = $this->registrationShow();
        $registration = $this->paidRegistration($show, ['is_checked_in' => true, 'date_checked_in' => '2020-01-01 09:00:00']);

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/registrations/{$registration->id}/check-in", ['override' => true])
            ->assertOk();

        $this->assertTrue($registration->refresh()->date_checked_in->gt(now()->subDay()));
    }

    #[Test]
    public function check_out_clears_the_check_in_and_needs_a_check_in(): void
    {
        $show = $this->registrationShow();
        $registration = $this->paidRegistration($show);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(self::BASE."/registrations/{$registration->id}/check-out")->assertStatus(422);

        $this->actingAs($admin)->postJson(self::BASE."/registrations/{$registration->id}/check-in", ['override' => true])->assertOk();
        $this->actingAs($admin)
            ->postJson(self::BASE."/registrations/{$registration->id}/check-out")
            ->assertOk()
            ->assertJsonPath('data.is_checked_in', false)
            ->assertJsonPath('data.checked_in_at', null);

        $this->assertNull($registration->refresh()->date_checked_in);
        $this->assertSame('registration.check_out', collect($this->auditEntries())->last()['action']);
    }

    #[Test]
    public function a_cancelled_registration_is_not_found(): void
    {
        $show = $this->registrationShow();
        $registration = ShowRegistrationFactory::new()->cancelled()->create(['show_id' => $show->id]);

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/registrations/{$registration->id}/check-in", ['override' => true])
            ->assertNotFound();
    }

    #[Test]
    public function the_waiver_reminder_goes_to_the_primary_registrant_only(): void
    {
        Mail::fake();
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/registrations/{$registration->id}/waiver-reminder")
            ->assertOk()
            ->assertJson(['ok' => true, 'queued' => true]);

        Mail::assertSent(WaiverReminderMail::class, fn (WaiverReminderMail $mail): bool => $mail->hasTo($registration->user->email));
        $this->assertSame('registration.waiver_reminder', collect($this->auditEntries())->last()['action']);
    }

    #[Test]
    public function the_outreach_mails_are_queued_not_sent_in_the_request(): void
    {
        Queue::fake();
        Mail::fake();
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(self::BASE."/registrations/{$registration->id}/waiver-reminder")->assertOk();
        $this->actingAs($admin)->postJson(self::BASE."/registrations/{$registration->id}/pay-link-email")->assertOk();

        Mail::assertNothingSent();
        Queue::assertPushed(SendOutreachMail::class, 2);
        Queue::assertPushed(SendOutreachMail::class, fn (SendOutreachMail $job): bool => $job->mail instanceof WaiverReminderMail
            && $job->to === $registration->user->email
            && $job->middleware()[0] instanceof ThrottleWaiverMail);
    }

    #[Test]
    public function a_failure_while_dispatching_is_logged_and_does_not_fail_the_request(): void
    {
        $errors = $this->captureErrorLog();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/registrations/{$registration->id}/pay-link-email")
            ->assertOk()
            ->assertJsonPath('queued', false)
            ->assertJsonStructure(['message']);

        $this->assertContains('Check-in email could not be queued', $errors->getArrayCopy());
    }

    #[Test]
    public function a_walk_in_registration_whose_mails_cannot_be_queued_stays_created_and_says_so(): void
    {
        $errors = $this->captureErrorLog();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
        $show = $this->registrationShow();
        $member = $this->memberWithCar();
        $car = Car::query()->where('user_id', $member->id)->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/shows/{$show->id}/registrations", $this->payload($show, $member, $car))
            ->assertStatus(201)
            ->assertJsonPath('queued', false)
            ->assertJsonStructure(['message']);

        $this->assertSame(1, ShowRegistration::query()->where('user_id', $member->id)->where('show_id', $show->id)->count());
    }

    #[Test]
    public function a_double_submitted_walk_in_registration_is_refused_and_writes_nothing_twice(): void
    {
        Queue::fake();
        $show = $this->registrationShow();
        $member = $this->memberWithCar();
        $car = Car::query()->where('user_id', $member->id)->firstOrFail();
        $admin = $this->admin();
        $payload = $this->payload($show, $member, $car);

        $this->actingAs($admin)->postJson(self::BASE."/shows/{$show->id}/registrations", $payload)->assertStatus(201);
        $this->actingAs($admin)->postJson(self::BASE."/shows/{$show->id}/registrations", $payload)->assertStatus(409);

        $registration = ShowRegistration::query()->where('user_id', $member->id)->where('show_id', $show->id)->firstOrFail();
        $this->assertSame(1, ShowRegistration::query()->where('user_id', $member->id)->where('show_id', $show->id)->count());
        $this->assertSame(1, UserGuest::query()->where('registration_id', $registration->id)->count());
        Queue::assertPushed(SendOutreachMail::class, 2);
    }

    #[Test]
    public function a_failed_outreach_job_is_logged(): void
    {
        $errors = $this->captureErrorLog();

        (new SendOutreachMail('a@example.test', new WaiverReminderMail('u', 'Ann', 'Show'), WaiverReminderMail::class))
            ->failed(new RuntimeException('smtp down'));

        $this->assertSame(['Check-in email failed'], $errors->getArrayCopy());
    }

    #[Test]
    public function the_waiver_reminder_needs_a_primary_email(): void
    {
        Mail::fake();
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show, [], User::factory()->create(['email' => '']));

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/registrations/{$registration->id}/waiver-reminder")
            ->assertStatus(422)
            ->assertJsonPath('errors.registration.0', 'Primary registrant has no email');

        Mail::assertNothingSent();
    }

    #[Test]
    public function the_pay_link_email_carries_the_billing_page_and_invoice(): void
    {
        Mail::fake();
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/registrations/{$registration->id}/pay-link-email")
            ->assertOk();

        Mail::assertSent(RegistrationPayLinkMail::class, function (RegistrationPayLinkMail $mail) use ($registration): bool {
            return $mail->hasTo($registration->user->email)
                && $mail->invoiceId === (int) $registration->invoice_id
                && str_ends_with($mail->payUrl, '/#/'.substr((string) $registration->user->profileURL, 2).'/billing');
        });
    }

    #[Test]
    public function the_waiver_sign_link_creates_the_pending_waiver_for_the_primary_or_a_guest(): void
    {
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);
        $guest = UserGuest::query()->where('registration_id', $registration->id)->firstOrFail();

        $primary = $this->actingAs($this->admin())
            ->postJson(self::BASE."/registrations/{$registration->id}/waiver-sign-url")
            ->assertOk()
            ->json();
        $this->assertStringContainsString('/waiver_sign/t/', $primary['url']);
        $this->assertSame($registration->user->fullName, $primary['personName']);

        $guestLink = $this->actingAs($this->admin())
            ->postJson(self::BASE."/registrations/{$registration->id}/waiver-sign-url", ['guest_id' => $guest->id])
            ->assertOk()
            ->json();
        $this->assertSame(trim($guest->first.' '.$guest->last), $guestLink['personName']);
        $this->assertNotNull(Waiver::query()->where('guest_id', $guest->id)->first());
    }

    #[Test]
    public function a_signed_waiver_has_no_sign_link_and_a_foreign_guest_is_not_found(): void
    {
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);
        $signed = Waiver::factory()->signed()->create();
        $registration->forceFill(['waiver_id' => $signed->id])->save();
        $foreign = $this->registrationWithCarAndGuest($show);
        $foreignGuest = UserGuest::query()->where('registration_id', $foreign->id)->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/registrations/{$registration->id}/waiver-sign-url")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Already signed');

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/registrations/{$registration->id}/waiver-sign-url", ['guest_id' => $foreignGuest->id])
            ->assertNotFound();
    }

    #[Test]
    public function the_options_list_members_with_a_car_who_are_not_registered_and_the_event_extras(): void
    {
        $show = $this->registrationShow();
        $free = $this->memberWithCar();
        $registered = $this->memberWithCar();
        $this->registrationWithCarAndGuest($show, [], $registered);
        ShowExtraFactory::new()->create(['show_id' => $show->id, 'name' => 'Dash plaque', 'price' => 5.00]);

        $options = $this->actingAs($this->admin())
            ->getJson(self::BASE."/shows/{$show->id}/registration-options")
            ->assertOk()
            ->json('data');

        $ids = array_column($options['eligible_users'], 'id');
        $this->assertContains($free->id, $ids);
        $this->assertNotContains($registered->id, $ids);
        $this->assertSame(['Adult', 'Child'], array_column($options['guest_types'], 'name'));
        $this->assertSame('Dash plaque', $options['extras'][0]['name']);
        $this->assertSame(500, $options['extras'][0]['price_cents']);
        $this->assertSame(0, $options['donations'][0]['id']);
        $this->assertNotEmpty($options['show_dates']);
    }

    #[Test]
    public function the_options_offer_every_shirt_type_at_its_default_when_the_event_has_no_offer(): void
    {
        $show = $this->registrationShow();

        $types = $this->actingAs($this->admin())->getJson(self::BASE."/shows/{$show->id}/registration-options")->assertOk()->json('data.shirt_types');

        $this->assertCount(ShirtType::query()->count(), $types);
        $this->assertSame(['id' => 1, 'name' => 'Mens S T-Shirt', 'price_cents' => 3500], $types[0]);
    }

    #[Test]
    public function the_options_and_the_write_follow_the_event_shirt_offer_and_price(): void
    {
        Mail::fake();
        $show = $this->registrationShow();
        DB::table('show_shirt_type')->insert(['show_id' => $show->id, 'shirt_type_id' => ShirtTypeFactory::MENS_M_TSHIRT, 'price' => 21.5]);

        $this->assertSame(
            [['id' => ShirtTypeFactory::MENS_M_TSHIRT, 'name' => 'Mens M T-Shirt', 'price_cents' => 2150]],
            $this->actingAs($this->admin())->getJson(self::BASE."/shows/{$show->id}/registration-options")->assertOk()->json('data.shirt_types'),
        );

        $member = $this->memberWithCar();
        $car = Car::query()->where('user_id', $member->id)->firstOrFail();
        $this->actingAs($this->admin())
            ->postJson(self::BASE."/shows/{$show->id}/registrations", array_merge($this->payload($show, $member, $car), ['shirts' => [['type_id' => ShirtTypeFactory::MENS_S_TSHIRT]]]))
            ->assertStatus(422)
            ->assertSee('Invalid shirt size selected');

        $this->actingAs($this->admin())->postJson(self::BASE."/shows/{$show->id}/registrations", $this->payload($show, $member, $car))->assertStatus(201);
        $this->assertSame('21.50', Shirt::query()->where('user_id', $member->id)->value('price'));
    }

    #[Test]
    public function the_garage_lists_the_members_cars(): void
    {
        $show = $this->registrationShow();
        $member = $this->memberWithCar();

        $cars = $this->actingAs($this->admin())
            ->getJson(self::BASE."/users/{$member->id}/garage?show_id={$show->id}")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $cars);
        $this->assertNotSame('', $cars[0]['body_name']);
        $this->assertSame([], $cars[0]['judging_types']);
    }

    #[Test]
    public function a_member_is_registered_on_site_with_the_legacy_payload_and_is_emailed(): void
    {
        Mail::fake();
        $show = $this->registrationShow();
        $member = $this->memberWithCar();
        $car = Car::query()->where('user_id', $member->id)->firstOrFail();
        $extra = ShowExtraFactory::new()->create(['show_id' => $show->id, 'price' => 5.00, 'min_value' => 1, 'max_value' => 10]);

        $response = $this->actingAs($this->admin())
            ->postJson(self::BASE."/shows/{$show->id}/registrations", $this->payload($show, $member, $car, $extra))
            ->assertStatus(201)
            ->assertJsonPath('data.is_checked_in', false);

        $registration = ShowRegistration::query()->where('user_id', $member->id)->where('show_id', $show->id)->firstOrFail();
        $this->assertSame($registration->id, $response->json('data.id'));
        $this->assertSame(10, (int) $registration->donation);
        $this->assertSame(2, (int) ShowExtraRegistration::query()->where('registration_id', $registration->id)->value('num_extras'));
        $this->assertGreaterThanOrEqual(55.0, (float) $registration->amount_owed);
        Mail::assertSent(WaiverReminderMail::class, 1);
        Mail::assertSent(RegistrationPayLinkMail::class, 1);
        $this->assertSame('registration.create', collect($this->auditEntries())->last()['action']);
    }

    #[Test]
    public function an_unknown_donation_is_stored_as_zero_as_legacy_did(): void
    {
        Mail::fake();
        $show = $this->registrationShow();
        $member = $this->memberWithCar();
        $car = Car::query()->where('user_id', $member->id)->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/shows/{$show->id}/registrations", array_merge($this->payload($show, $member, $car), ['donation' => 7, 'extras' => [], 'guests' => [], 'shirts' => []]))
            ->assertStatus(201);

        $this->assertSame(0, (int) ShowRegistration::query()->where('user_id', $member->id)->value('donation'));
    }

    #[Test]
    public function on_site_registration_refuses_a_member_already_registered_with_409(): void
    {
        $show = $this->registrationShow();
        $registered = $this->memberWithCar();
        $this->registrationWithCarAndGuest($show, [], $registered);
        $car = Car::query()->where('user_id', $registered->id)->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/shows/{$show->id}/registrations", $this->payload($show, $registered, $car))
            ->assertStatus(409)
            ->assertJsonPath('message', 'This member already has a registration record for this event');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function badOnSiteRegistrations(): array
    {
        return [
            'no cars' => [['cars' => []], 'Select at least one car'],
            'guest without email' => [['guests' => [['first' => 'Pat', 'last' => 'Guest', 'email' => '', 'type_id' => 1]]], 'Each guest row must include first name, last name, email, and type (or clear the row).'],
            'guest email is not valid' => [['guests' => [['first' => 'Pat', 'last' => 'Guest', 'email' => 'not-an-email', 'type_id' => 1]]], 'Each guest needs a valid email'],
            'unknown guest type' => [['guests' => [['first' => 'Pat', 'last' => 'Guest', 'email' => 'pat@example.com', 'type_id' => 9]]], 'Each guest needs a valid type'],
            'shirt without size' => [['shirts' => [['type_id' => null]]], 'Each shirt row needs a size'],
            'arrival outside the show' => [['arrival_date' => '1999-01-01'], 'Invalid arrival date for this event'],
            'no arrival time' => [['arrival_time' => ''], 'Arrival date and time are required'],
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[Test]
    #[DataProvider('badOnSiteRegistrations')]
    public function on_site_registration_uses_the_legacy_checks_and_messages(array $change, string $message): void
    {
        Mail::fake();
        $show = $this->registrationShow();
        $member = $this->memberWithCar();
        $car = Car::query()->where('user_id', $member->id)->firstOrFail();
        $body = array_merge($this->payload($show, $member, $car), $change);

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/shows/{$show->id}/registrations", $body)
            ->assertStatus(422)
            ->assertSee($message);

        $this->assertSame(0, ShowRegistration::query()->where('user_id', $member->id)->count());
        Mail::assertNothingSent();
    }

    #[Test]
    public function on_site_registration_refuses_a_car_that_is_not_the_members(): void
    {
        $show = $this->registrationShow();
        $member = $this->memberWithCar();
        $otherCar = Car::query()->where('user_id', $this->memberWithCar()->id)->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/shows/{$show->id}/registrations", array_merge($this->payload($show, $member, $otherCar)))
            ->assertStatus(422)
            ->assertSee('Invalid car selection');
    }

    #[Test]
    public function on_site_registration_refuses_a_guest_with_the_members_email(): void
    {
        $show = $this->registrationShow();
        $member = $this->memberWithCar();
        $car = Car::query()->where('user_id', $member->id)->firstOrFail();
        $body = array_merge($this->payload($show, $member, $car), [
            'guests' => [['first' => 'Pat', 'last' => 'Guest', 'email' => (string) $member->email, 'type_id' => 1]],
        ]);

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/shows/{$show->id}/registrations", $body)
            ->assertStatus(422)
            ->assertSee('Guest email must differ from the primary registrant');
    }

    #[Test]
    public function a_member_without_an_email_cannot_be_registered_on_site(): void
    {
        $show = $this->registrationShow();
        $member = $this->memberWithCar(['email' => '']);
        $car = Car::query()->where('user_id', $member->id)->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/shows/{$show->id}/registrations", $this->payload($show, $member, $car))
            ->assertStatus(422)
            ->assertJsonPath('errors.user_id.0', 'Member has no email on file; add one before registering');
    }

    /**
     * A paid registration in the show, owed 45.00 (car 25 + adult 20).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function paidRegistration(CarShow $show, array $overrides = []): ShowRegistration
    {
        return $this->registrationWithCarAndGuest($show, array_merge(['is_paid' => true, 'amount_received' => '45.00'], $overrides));
    }

    /** Paid, with a signed waiver for the primary and the guest. */
    private function signedRegistration(CarShow $show): ShowRegistration
    {
        $registration = $this->registrationWithCarAndGuest($show, ['is_paid' => true, 'amount_received' => '45.00']);
        $waiver = Waiver::factory()->signed()->create();
        $registration->forceFill(['waiver_id' => $waiver->id])->save();

        $guest = UserGuest::query()->where('registration_id', $registration->id)->firstOrFail();
        $guest->forceFill(['waiver_id' => Waiver::factory()->signed()->create()->id])->save();

        return $registration;
    }

    /** @param  array<string, mixed>  $overrides */
    private function memberWithCar(array $overrides = []): User
    {
        $member = User::factory()->create($overrides + ['email' => fake()->unique()->safeEmail()]);
        Car::factory()->create(['user_id' => $member->id, 'body_id' => CarBodyFactory::E24]);

        return $member;
    }

    /** @return \ArrayObject<int, string> */
    private function captureErrorLog(): \ArrayObject
    {
        /** @var \ArrayObject<int, string> $errors */
        $errors = new \ArrayObject;
        Event::listen(MessageLogged::class, function (MessageLogged $log) use ($errors): void {
            if ($log->level === 'error') {
                $errors[] = $log->message;
            }
        });

        return $errors;
    }

    /**
     * The legacy create payload for a member, one car, one adult guest, one shirt and two of an extra.
     *
     * @return array<string, mixed>
     */
    private function payload(CarShow $show, User $member, Car $car, ?ShowExtra $extra = null): array
    {
        return [
            'user_id' => $member->id,
            'arrival_date' => $show->date_start?->format('Y-m-d'),
            'arrival_time' => 'morning',
            'donation' => 10,
            'cars' => [['id' => $car->id, 'judging_type_id' => null]],
            'guests' => [['first' => 'Pat', 'last' => 'Guest', 'email' => 'pat@example.com', 'type_id' => 1]],
            'shirts' => [['type_id' => ShirtTypeFactory::MENS_M_TSHIRT]],
            'extras' => $extra === null ? [] : [['show_extra_id' => $extra->id, 'num_extras' => 2]],
        ];
    }
}
