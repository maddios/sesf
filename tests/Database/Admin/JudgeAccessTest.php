<?php

namespace Tests\Database\Admin;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;

/**
 * Judges may use the admin area only for judging: every admin-api route outside admin-api.judging.* answers 403
 * for a judge (the judging routes themselves are covered by JudgingAdminTest). The impersonation status and stop
 * routes are open to any signed-in user on purpose (the banner of an impersonated session).
 */
class JudgeAccessTest extends AdminTestCase
{
    private const OPEN_TO_SIGNED_IN = ['admin-api.impersonation.status', 'admin-api.impersonation.stop'];

    #[Test]
    public function a_judge_is_refused_every_non_judging_admin_api_route(): void
    {
        $routes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with((string) $route->getName(), 'admin-api.')
                && ! str_starts_with((string) $route->getName(), 'admin-api.judging.')
                && ! in_array($route->getName(), self::OPEN_TO_SIGNED_IN, true));
        $this->assertGreaterThan(30, $routes->count());

        $judge = $this->actor('judge');
        foreach ($routes as $route) {
            $method = collect($route->methods())->first(fn (string $verb): bool => $verb !== 'HEAD');
            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $status = $this->actingAs($judge)->json($method, $uri)->status();
            $this->assertSame(403, $status, "{$method} {$uri}");
        }
    }

    #[Test]
    public function the_event_lookup_is_admin_only(): void
    {
        $this->actingAs($this->actor('judge'))->getJson('/admin-api/lookups/events')->assertForbidden();
    }
}
