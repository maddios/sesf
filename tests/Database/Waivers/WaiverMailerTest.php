<?php

namespace Tests\Database\Waivers;

use App\Mail\WaiverReminderMail;
use App\Mail\WaiverRequestMail;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Waivers\WaiverMailer;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/** Request and reminder emails: links, recipients and the 10-minute dedupe. */
class WaiverMailerTest extends WaiverTestCase
{
    private function mailer(): WaiverMailer
    {
        return $this->app->make(WaiverMailer::class);
    }

    #[Test]
    public function requests_go_to_the_registrant_and_guest_with_their_own_links(): void
    {
        $registration = $this->registration();
        $member = User::query()->findOrFail($registration->user_id);
        $guest = $this->guest($registration);

        $result = $this->mailer()->sendRequests([$registration, $guest]);

        $this->assertSame(['sent' => 2, 'skipped' => 0, 'failed' => 0], $result);
        $own = Waiver::query()->findOrFail($registration->fresh()?->waiver_id);
        $theirs = Waiver::query()->findOrFail($guest->fresh()?->waiver_id);
        Mail::assertSent(WaiverRequestMail::class, fn (WaiverRequestMail $m): bool => $m->hasTo($member->email)
            && $m->url === 'https://sesf.example.test/waiver_sign/t/'.$this->tracking($own) && $m->showName === 'Spring Fest');
        Mail::assertSent(WaiverRequestMail::class, fn (WaiverRequestMail $m): bool => $m->hasTo((string) $guest->email)
            && $m->url === 'https://sesf.example.test/waiver_sign/t/'.$this->tracking($theirs));
    }

    #[Test]
    public function a_person_gets_at_most_one_request_per_ten_minutes(): void
    {
        $registration = $this->registration();

        $this->assertSame(1, $this->mailer()->sendRequests([$registration])['sent']);
        $this->assertSame(['sent' => 0, 'skipped' => 1, 'failed' => 0], $this->mailer()->sendRequests([$registration]));
        Mail::assertSentCount(1);

        $this->travel(11)->minutes();
        $this->assertSame(1, $this->mailer()->sendRequests([$registration])['sent']);
    }

    #[Test]
    public function signed_waivers_and_guests_without_email_are_skipped(): void
    {
        $registration = $this->registration();
        $signed = Waiver::factory()->signed()->create(['show_registration_id' => $registration->id]);
        $registration->forceFill(['waiver_id' => $signed->id])->save();
        $guest = $this->guest($registration);
        $guest->forceFill(['email' => null])->save();

        $this->assertSame(['sent' => 0, 'skipped' => 2, 'failed' => 0], $this->mailer()->sendRequests([$registration, $guest]));
        Mail::assertNothingSent();
    }

    #[Test]
    public function reminders_go_only_to_registrants_with_unsigned_party_members(): void
    {
        $show = $this->show();
        $complete = $this->registration($show);
        $done = Waiver::factory()->signed()->create(['show_registration_id' => $complete->id]);
        $complete->forceFill(['waiver_id' => $done->id])->save();
        $incomplete = $this->registration($show, ['first' => '<b>Hostile</b>']);
        $incompleteUser = User::query()->findOrFail($incomplete->user_id);
        $completeUser = User::query()->findOrFail($complete->user_id);

        $this->assertSame(1, $this->mailer()->sendReminders($show));
        Mail::assertSent(WaiverReminderMail::class, fn (WaiverReminderMail $m): bool => $m->hasTo($incompleteUser->email)
            && $m->portalUrl === 'https://sesf.example.test/waiver/'.$show->id);
        Mail::assertNotSent(WaiverReminderMail::class, fn (WaiverReminderMail $m): bool => $m->hasTo($completeUser->email));

        // Deduped within the window.
        $this->assertSame(0, $this->mailer()->sendReminders($show));
    }

    #[Test]
    public function reminders_skip_cancelled_registrations_and_disabled_registrants(): void
    {
        $show = $this->show();
        $cancelled = $this->registration($show);
        $cancelled->forceFill(['is_cancelled' => true])->save();
        $disabled = $this->registration($show);
        User::query()->findOrFail($disabled->user_id)->delete();

        $this->assertSame([], $this->mailer()->reminderRecipients($show));
        $this->assertSame(0, $this->mailer()->sendReminders($show));
        Mail::assertNothingSent();
    }

    #[Test]
    public function requests_skip_cancelled_registrations(): void
    {
        $registration = $this->registration();
        $registration->forceFill(['is_cancelled' => true])->save();
        $guest = $this->guest($registration);

        $this->assertSame(['sent' => 0, 'skipped' => 2, 'failed' => 0], $this->mailer()->sendRequests([$registration, $guest]));
        Mail::assertNothingSent();
    }
}
