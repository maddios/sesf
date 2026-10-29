<?php

namespace Tests\Unit\Waivers;

use App\Mail\WaiverReminderMail;
use App\Mail\WaiverRequestMail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Mail rendering escapes hostile names and links. */
class WaiverMailRenderTest extends TestCase
{
    #[Test]
    public function the_request_mail_escapes_names_and_links_the_sign_url(): void
    {
        config(['sesf.reg_email' => 'reg@example.test']);
        $mail = new WaiverRequestMail('https://sesf.example.test/waiver_sign/t/'.str_repeat('a', 32), '<script>alert(1)</script>', 'Fest "<b>" & Co');

        $html = $mail->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('https://sesf.example.test/waiver_sign/t/'.str_repeat('a', 32), $html);
        $mail->assertHasSubject('Southeast Sharkfest Waiver Signature - Please Complete');
        $mail->assertFrom('reg@example.test');
    }

    #[Test]
    public function the_reminder_mail_escapes_names_and_links_the_portal(): void
    {
        $mail = new WaiverReminderMail('https://sesf.example.test/waiver/9', '<img src=x onerror=1>', '<i>Show</i>');

        $html = $mail->render();

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<i>Show</i>', $html);
        $this->assertStringContainsString('https://sesf.example.test/waiver/9', $html);
        $mail->assertHasSubject('Reminder: complete waivers for <i>Show</i>');
    }
}
