<?php

namespace Tests\Feature\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Legacy /admin and /admin2 URLs redirect (302) into the SPA admin, query kept inside the hash.
 */
class LegacyAdminRedirectTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function redirects(): array
    {
        return [
            'admin root' => ['/admin', '/#/admin'],
            'frozennode model page' => ['/admin/sponsor', '/#/admin'],
            'admin2 root' => ['/admin2', '/#/admin'],
            'judging' => ['/admin2/judging_list', '/#/admin/judging'],
            'judging with id' => ['/admin2/judging_list/12', '/#/admin/judging'],
            'check-in' => ['/admin2/checkin_list', '/#/admin/check-in'],
            't-shirts' => ['/admin2/tshirt_list', '/#/admin/tshirts'],
            'waivers' => ['/admin2/waivers_list', '/#/admin/waivers'],
            'members' => ['/admin2/member_list', '/#/admin/members-report'],
            'events' => ['/admin2/event_summary', '/#/admin/events-report'],
            'extras' => ['/admin2/extra_list', '/#/admin/extras-report'],
            'unknown' => ['/admin2/nope', '/#/admin'],
            'query kept' => ['/admin2/event_summary?show_id=5&x=a%20b', '/#/admin/events-report?show_id=5&x=a+b'],
            'admin query kept' => ['/admin?tab=1', '/#/admin?tab=1'],
        ];
    }

    #[Test]
    #[DataProvider('redirects')]
    public function legacy_admin_urls_redirect_into_the_spa(string $from, string $to): void
    {
        $response = $this->get($from);

        $response->assertStatus(302);
        $this->assertSame(url('/').$to, $response->headers->get('Location'));
    }

    #[Test]
    public function injected_header_characters_in_the_query_are_encoded(): void
    {
        $location = (string) $this->get('/admin2/member_list?a=%0d%0aSet-Cookie:x')->headers->get('Location');

        $this->assertStringNotContainsString("\n", $location);
        $this->assertStringNotContainsString("\r", $location);
        $this->assertStringEndsWith('/#/admin/members-report?a=Set-Cookie%3Ax', $location);
    }
}
