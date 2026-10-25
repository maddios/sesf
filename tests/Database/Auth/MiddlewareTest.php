<?php

use App\Enums\UserType;
use App\Models\CarShow;
use App\Models\CarShowJudge;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    Route::middleware('admin')->get('/_test/admin', fn () => 'admin-ok');
    Route::middleware('judge_or_admin')->get('/_test/judge', fn () => 'judge-ok');
    Route::middleware('judge_for_show')->get('/_test/shows/{show}', fn () => 'show-ok');
    Route::middleware('judge_for_show')->get('/_test/query', fn () => 'query-ok');
});

/**
 * Sends a GET through the HTTP kernel as $as (null for a guest). The user is set on the default
 * guard, which is how the app resolves $request->user(). Each call starts with fresh guards, so
 * no user carries over from an earlier call.
 */
function mwGet(string $uri, ?User $as = null, bool $json = true): Response
{
    Auth::forgetGuards();

    if ($as !== null) {
        Auth::guard()->setUser($as);
    }

    $request = Request::create($uri, 'GET', [], [], [], [
        'HTTP_ACCEPT' => $json ? 'application/json' : 'text/html',
    ]);

    return app(Kernel::class)->handle($request);
}

/** Decoded JSON body of a response. */
function mwJson(Response $response): mixed
{
    return json_decode((string) $response->getContent(), true);
}

/** Whether the response is an HTML redirect to the SPA login. */
function mwRedirectsToLogin(Response $response): bool
{
    return $response->isRedirect() && str_contains((string) $response->headers->get('Location'), '/#/login');
}

/** A judge assigned to $show. */
function mwJudgeAssignedTo(CarShow $show): User
{
    $judge = User::factory()->judge()->create();
    CarShowJudge::factory()->create(['user_id' => $judge->id, 'show_id' => $show->id]);

    return $judge;
}

/**
 * The caller for one matrix row. A null user is a guest.
 */
function mwActor(string $who, CarShow $show): ?User
{
    return match ($who) {
        'anonymous' => null,
        'regular' => User::factory()->create(['type_id' => UserType::Regular->value]),
        'judge-assigned' => mwJudgeAssignedTo($show),
        'judge-unassigned' => User::factory()->judge()->create(),
        'admin' => User::factory()->admin()->create(),
        default => throw new InvalidArgumentException("Unknown actor {$who}"),
    };
}

/**
 * The expected outcome for a route and caller: 'allow', 'guest' (401 JSON or login redirect) or
 * 'forbidden' (403).
 */
function mwExpected(string $route, string $who): string
{
    if ($who === 'anonymous') {
        return 'guest';
    }

    if ($who === 'admin') {
        return 'allow';
    }

    return match ($route) {
        'admin' => 'forbidden',
        'judge_or_admin' => $who === 'regular' ? 'forbidden' : 'allow',
        'judge_for_show' => $who === 'judge-assigned' ? 'allow' : 'forbidden',
        default => throw new InvalidArgumentException("Unknown route {$route}"),
    };
}

$accessMatrix = [];
foreach (['admin', 'judge_or_admin', 'judge_for_show'] as $route) {
    foreach (['anonymous', 'regular', 'judge-assigned', 'judge-unassigned', 'admin'] as $who) {
        foreach ([true, false] as $json) {
            $accessMatrix["{$route} / {$who} / ".($json ? 'JSON' : 'HTML')] = [$route, $who, $json];
        }
    }
}

dataset('access matrix', $accessMatrix);

test('role middleware access matrix: guests get 401 JSON or an HTML login redirect, signed-in users get 403 or pass', function (string $route, string $who, bool $json) {
    $show = CarShow::factory()->create();
    $uri = $route === 'judge_for_show' ? '/_test/shows/'.$show->id : ($route === 'admin' ? '/_test/admin' : '/_test/judge');

    $response = mwGet($uri, mwActor($who, $show), $json);
    $expected = mwExpected($route, $who);

    match ($expected) {
        'allow' => expect($response->getStatusCode())->toBe(200),
        'guest' => $json
            ? expect([$response->getStatusCode(), mwJson($response)])->toBe([401, ['message' => 'Unauthenticated.']])
            : expect(mwRedirectsToLogin($response))->toBeTrue(),
        'forbidden' => $json
            ? expect([$response->getStatusCode(), mwJson($response)])->toBe([403, ['message' => 'Forbidden.']])
            : expect([$response->getStatusCode(), mwRedirectsToLogin($response)])->toBe([403, false]),
        default => throw new InvalidArgumentException("Unknown expectation {$expected}"),
    };
})->with('access matrix');

test('judge_for_show: the show id comes from the route parameter, and only assigned judges pass', function () {
    $show = CarShow::factory()->create();
    $judge = mwJudgeAssignedTo($show);
    $otherShow = CarShow::factory()->create();

    expect(mwGet('/_test/shows/'.$show->id, $judge)->getStatusCode())->toBe(200);
    expect(mwGet('/_test/shows/'.$otherShow->id, $judge)->getStatusCode())->toBe(403);
    expect(mwJson(mwGet('/_test/shows/'.$otherShow->id, $judge)))->toBe(['message' => 'Forbidden.']);
});

test('judge_for_show: a route parameter wins over a query parameter with the same name', function () {
    $show = CarShow::factory()->create();
    $judge = mwJudgeAssignedTo($show);
    $otherShow = CarShow::factory()->create();

    expect(mwGet('/_test/shows/'.$otherShow->id.'?show_id='.$show->id, $judge)->getStatusCode())->toBe(403);
});

test('judge_for_show: the show id comes from the query when there is no route parameter', function () {
    $show = CarShow::factory()->create();
    $judge = mwJudgeAssignedTo($show);
    $otherShow = CarShow::factory()->create();

    expect(mwGet('/_test/query?show_id='.$show->id, $judge)->getStatusCode())->toBe(200);
    expect(mwGet('/_test/query?show_id='.$otherShow->id, $judge)->getStatusCode())->toBe(403);
});

test('judge_for_show: a missing, zero, negative, or non-numeric show id is denied', function (string $query) {
    $judge = mwJudgeAssignedTo(CarShow::factory()->create());

    expect(mwGet('/_test/query'.$query, $judge)->getStatusCode())->toBe(403);
})->with([
    'missing' => [''],
    'zero' => ['?show_id=0'],
    'negative' => ['?show_id=-1'],
    'text' => ['?show_id=abc'],
    'leading space' => ['?show_id=%201'],
    'array' => ['?show_id[]=1'],
    'decimal' => ['?show_id=1.5'],
]);

test('judge_for_show: an administrator passes for any show, with or without an assignment', function () {
    $admin = User::factory()->admin()->create();
    $show = CarShow::factory()->create();

    expect(mwGet('/_test/shows/'.$show->id, $admin)->getStatusCode())->toBe(200);
    expect(mwGet('/_test/query?show_id='.$show->id, $admin)->getStatusCode())->toBe(200);
});

test('judge_for_show: a regular member assigned to the show is still refused', function () {
    $show = CarShow::factory()->create();
    $member = User::factory()->create(['type_id' => UserType::Regular->value]);
    CarShowJudge::factory()->create(['user_id' => $member->id, 'show_id' => $show->id]);

    expect(mwGet('/_test/shows/'.$show->id, $member)->getStatusCode())->toBe(403);
});

test('role middleware: the admin route returns the admin body to an administrator over HTML', function () {
    $admin = User::factory()->admin()->create();

    $response = mwGet('/_test/admin', $admin, false);

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('admin-ok');
});
