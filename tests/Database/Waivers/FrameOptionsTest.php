<?php

namespace Tests\Database\Waivers;

use App\Services\Waivers\WaiverSignService;
use PHPUnit\Framework\Attributes\Test;

/** Clickjacking headers: the sign pages may be framed by this site only; nothing else may be framed. */
class FrameOptionsTest extends WaiverTestCase
{
    #[Test]
    public function the_sign_and_thank_you_pages_may_be_framed_by_this_site_only(): void
    {
        $this->withoutVite();
        $waiver = app(WaiverSignService::class)->createPending($this->registration());

        foreach (['/waiver_sign/t/'.$this->tracking($waiver), '/waiver_sign/thank-you/'.$this->tracking($waiver), '/waiver_sign/t/'.str_repeat('0', 32)] as $url) {
            $this->get($url)
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
        }
    }

    #[Test]
    public function the_portal_spa_and_api_may_not_be_framed(): void
    {
        $this->withoutVite();
        $show = $this->registration()->carShow;

        foreach (['/waiver/'.$show?->id, '/waiver/'.$show?->id.'/status', '/'] as $url) {
            $this->get($url)
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
        }
    }
}
