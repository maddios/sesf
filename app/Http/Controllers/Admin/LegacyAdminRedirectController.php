<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /admin[/...] and GET /admin2/{section}[/...]: 302 to the SPA admin (/#/admin/...), with the
 * query string moved inside the hash. Unknown sections go to the dashboard.
 */
final class LegacyAdminRedirectController extends Controller
{
    /** Legacy /admin2 section => SPA admin path. */
    public const ADMIN2_SECTIONS = [
        'judging_list' => 'judging',
        'checkin_list' => 'checkin',
        'tshirt_list' => 'tshirts',
        'waivers_list' => 'waivers',
        'member_list' => 'reports/members',
        'event_summary' => 'reports/event-summary',
        'extra_list' => 'reports/extras',
    ];

    public function admin(Request $request): RedirectResponse
    {
        return $this->toSpa($request, '');
    }

    public function admin2(Request $request, ?string $section = null): RedirectResponse
    {
        return $this->toSpa($request, self::ADMIN2_SECTIONS[(string) $section] ?? '');
    }

    private function toSpa(Request $request, string $section): RedirectResponse
    {
        $target = '/#/admin'.($section === '' ? '' : '/'.$section);
        $query = $request->query();

        return redirect($target.($query === [] ? '' : '?'.http_build_query($query)), 302);
    }
}
