<?php

namespace Tests\Database\Api\Registration\Write;

use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\Database\Api\Registration\RegistrationTestCase;

/**
 * Helpers for the registration write tests: a valid body and the POST helpers.
 */
abstract class WriteTestCase extends RegistrationTestCase
{
    protected const SAVE = '/event/registration';

    protected const QUOTE = '/event/registration/quote';

    protected const EXTRA = '/event/extra';

    /**
     * Signs in for one request with a fresh session: AuthenticateSession keeps the first user's
     * password hash in the session, so switching users in one test would otherwise log out.
     */
    protected function signIn(User $user): static
    {
        $this->flushSession();

        return parent::signIn($user);
    }

    /**
     * A valid registration body for the show (arrival on the first show day).
     *
     * @param  list<int>  $carIds
     * @param  array<string, mixed>  $overrides  merged into `registration`
     * @return array<string, mixed>
     */
    protected function body(CarShow $show, array $carIds, array $overrides = []): array
    {
        return ['registration' => array_merge([
            'cars' => array_map(fn (int $id): array => ['id' => $id, 'judging_type' => null], $carIds),
            'guests' => [],
            'shirts' => [],
            'extras' => (object) [],
            'arrival_date' => ['data' => $show->date_start?->format('Y-m-d')],
            'arrival_time' => 'morning',
            'donation' => 0,
        ], $overrides)];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    protected function save(User $actor, CarShow $show, array $body, ?int $userId = null): TestResponse
    {
        $query = '?show_id='.$show->id.($userId === null ? '' : '&user_id='.$userId);

        return $this->signIn($actor)->postJson(self::SAVE.$query, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    protected function quote(User $actor, CarShow $show, array $body): TestResponse
    {
        return $this->signIn($actor)->postJson(self::QUOTE.'?show_id='.$show->id, $body);
    }

    protected function storedRegistration(CarShow $show, User $user): ?ShowRegistration
    {
        return ShowRegistration::query()->where('show_id', $show->id)->where('user_id', $user->id)->first();
    }
}
