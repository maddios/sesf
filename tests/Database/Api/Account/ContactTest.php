<?php

namespace Tests\Database\Api\Account;

use App\Mail\ContactReportMail;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ContactTest extends AccountTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function report(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Visitor Vic',
            'email' => 'vic@example.test',
            'subject' => 'Parking question',
            'message' => 'Is there parking at the hotel?',
        ], $overrides);
    }

    #[Test]
    public function an_anonymous_visitor_sends_a_report_replying_to_them(): void
    {
        $this->postJson('/home/report', $this->report())
            ->assertOk()
            ->assertContent('""');

        Mail::assertSent(ContactReportMail::class, function (ContactReportMail $mail) {
            return $mail->hasTo(config('sesf.reg_email'))
                && $mail->hasReplyTo('vic@example.test', 'Visitor Vic')
                && $mail->envelope()->subject === 'Parking question';
        });
    }

    #[Test]
    public function a_signed_in_member_only_needs_a_subject_and_message(): void
    {
        $member = $this->makeUser(['first' => 'Mem', 'last' => 'Ber', 'email' => 'member-report@example.test']);

        $this->actingAs($member)->postJson('/home/report', ['subject' => 'Logo', 'message' => 'Love the logo.'])
            ->assertOk();

        Mail::assertSent(ContactReportMail::class, fn (ContactReportMail $mail) => $mail->hasReplyTo('member-report@example.test'));
    }

    #[Test]
    public function a_signed_in_member_cannot_spoof_the_sender_name_or_email(): void
    {
        $member = $this->makeUser(['email' => 'real@example.test']);

        $this->actingAs($member)->postJson('/home/report', $this->report(['email' => 'spoof@example.test']))
            ->assertOk();

        Mail::assertSent(ContactReportMail::class, fn (ContactReportMail $mail) => $mail->hasReplyTo('real@example.test')
            && ! $mail->hasReplyTo('spoof@example.test'));
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function invalidReports(): array
    {
        return [
            'name missing' => ['name', null],
            'name with a line break' => ['name', "Vic\r\nBcc: spy@example.test"],
            'email missing' => ['email', null],
            'email invalid' => ['email', 'not-an-email'],
            'email with a line break' => ['email', "vic@example.test\r\nBcc: spy@example.test"],
            'subject missing' => ['subject', null],
            'subject with a line break' => ['subject', "Hi\nBcc: spy@example.test"],
            'subject too long' => ['subject', str_repeat('s', 151)],
            'message missing' => ['message', null],
            'message too long' => ['message', str_repeat('m', 5001)],
            'location too long' => ['location', str_repeat('l', 501)],
        ];
    }

    #[Test]
    #[DataProvider('invalidReports')]
    public function invalid_reports_are_refused_and_not_sent(string $field, mixed $value): void
    {
        $body = $this->report([$field => $value]);
        if ($value === null) {
            unset($body[$field]);
        }

        $this->postJson('/home/report', $body)->assertStatus(400)->assertJsonIsArray();
        Mail::assertNothingSent();
    }

    #[Test]
    public function an_anonymous_visitor_does_not_need_a_turnstile_token(): void
    {
        $this->postJson('/home/report', $this->report())->assertOk();
    }

    #[Test]
    public function a_visitor_is_limited_to_three_reports_a_minute(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/home/report', $this->report())->assertOk();
        }

        $this->postJson('/home/report', $this->report())->assertStatus(429);
    }

    #[Test]
    public function a_failing_mailer_gets_a_legacy_failure_answer(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->postJson('/home/report', $this->report())
            ->assertStatus(400)
            ->assertExactJson(['Failed to send message']);
    }

    #[Test]
    public function a_post_without_a_csrf_token_gets_419(): void
    {
        $this->app->instance('env', 'local');

        $this->withHeader('Accept', 'application/json')
            ->post('/home/report', $this->report())
            ->assertStatus(419);
    }
}
