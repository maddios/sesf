<?php

namespace Tests\Database\Judging;

use App\Enums\UserType;
use App\Models\CarShowJudge;
use App\Support\Judging\JudgingId;
use PHPUnit\Framework\Attributes\DataProvider;

final class JudgingAuthorizationTest extends JudgingTestCase
{
    /**
     * Expected status per actor (JudgingWorld::ACTORS order: anonymous, member, owner,
     * other judge, assigned judge, admin) for each endpoint.
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function endpoints(): array
    {
        $matrix = [
            'judging page' => ['page', [302, 403, 403, 403, 200, 200]],
            'judgingData' => ['data', [401, 403, 403, 403, 200, 200]],
            'judgingSubmit' => ['submit', [401, 403, 403, 403, 200, 200]],
            'judging_answers before release' => ['answers', [302, 403, 403, 403, 200, 200]],
            'judging_draw' => ['draw', [302, 403, 403, 403, 200, 200]],
            'car QR' => ['qr', [401, 403, 403, 403, 200, 200]],
            'QR sheet' => ['sheet', [401, 403, 403, 403, 200, 200]],
        ];

        $cases = [];
        foreach ($matrix as $label => [$endpoint, $statuses]) {
            foreach (JudgingWorld::ACTORS as $index => $who) {
                $cases["{$label} as {$who}"] = [$endpoint, $who, $statuses[$index]];
            }
        }

        return $cases;
    }

    #[DataProvider('endpoints')]
    public function test_every_judging_endpoint_is_authorized(string $endpoint, string $who, int $status): void
    {
        $user = $this->world->actor($who);
        if ($user !== null) {
            $this->actingAs($user);
        }
        $token = $this->world->token();
        $showId = $this->world->show->id;

        $response = match ($endpoint) {
            'page' => $this->get('/judging/'.$token),
            'data' => $this->getJson('/judgingData/'.$token),
            'submit' => $this->postJson('/judgingSubmit/'.$token, ['data' => []]),
            'answers' => $this->get('/judging_answers/'.$token),
            'draw' => $this->get('/judging_draw/'.$showId),
            'qr' => $this->get('/judging_qr_code/'.$token),
            'sheet' => $this->get('/judging_qr_code/show/'.$showId),
            default => throw new \InvalidArgumentException($endpoint),
        };

        $response->assertStatus($status);
    }

    public function test_guests_go_to_the_spa_login_and_back(): void
    {
        $token = $this->world->token();

        $this->get('/judging/'.$token)->assertRedirect('/#/login?redirect='.rawurlencode('/judging/'.$token));
        $this->get('/judging_answers/'.$token)->assertRedirect('/#/login?redirect='.rawurlencode('/judging_answers/'.$token));
    }

    /**
     * Dates are built in the test, not here: data providers run before the app boots, so now()
     * here would use PHP's default timezone instead of the app's and be a day off in the evening.
     *
     * @return array<string, array{0: string}>
     */
    public static function visibleResults(): array
    {
        return ['released' => ['released'], 'ended' => ['ended']];
    }

    #[DataProvider('visibleResults')]
    public function test_the_owner_sees_answers_once_results_are_visible(string $why): void
    {
        $this->world->show->forceFill($why === 'released'
            ? ['is_judging_released' => true]
            : ['date_start' => now()->subDays(3)->toDateString(), 'date_end' => now()->subDay()->toDateString()])->save();
        $this->actingAs($this->world->owner);

        $this->get('/judging_answers/'.$this->world->token())->assertOk()->assertSee('Judging summary')->assertSee('Back to car profile');
    }

    public function test_a_show_ending_today_is_not_yet_visible_to_the_owner(): void
    {
        $this->world->show->forceFill(['date_start' => now()->subDays(2)->toDateString(), 'date_end' => now()->toDateString()])->save();
        $this->actingAs($this->world->owner);

        $this->get('/judging_answers/'.$this->world->token())->assertForbidden();
    }

    public function test_other_members_are_refused_after_release(): void
    {
        $this->world->show->forceFill(['is_judging_released' => true])->save();
        $this->actingAs($this->world->member);

        $this->get('/judging_answers/'.$this->world->token())->assertForbidden();
    }

    public function test_an_assigned_judge_may_not_judge_their_own_car(): void
    {
        $owner = $this->world->owner;
        CarShowJudge::factory()->create(['user_id' => $owner->id, 'show_id' => $this->world->show->id]);
        $owner->forceFill(['type_id' => UserType::Judge])->save();
        $this->actingAs($owner);

        $this->get('/judging/'.$this->world->token())->assertForbidden()->assertSee('You may not judge your own car.');
        $this->submit(['q_'.$this->world->q1->id => 5])->assertForbidden()->assertJsonPath('errors.0', 'You may not judge your own car.');
    }

    public function test_judges_of_other_shows_get_a_sign_out_page(): void
    {
        $this->actingAs($this->world->otherJudge);

        $this->get('/judging/'.$this->world->token())
            ->assertForbidden()
            ->assertSee('Not a judge for this event')
            ->assertSee('action="/login/logout"', false);
    }

    public function test_legacy_numeric_ids_work_and_unknown_or_tampered_ids_do_not(): void
    {
        $this->actingAs($this->world->judge);
        $id = $this->world->entry->id;

        $this->getJson('/judgingData/'.$id)->assertOk();
        $this->getJson('/judgingData/'.JudgingId::encode($id + 1000))->assertNotFound();
        $this->getJson('/judgingData/IOgXHXRZVkJlpct3D')->assertNotFound();
        $this->getJson('/judgingData/abc%2F..%2Fx')->assertNotFound();
    }

    public function test_extra_path_segments_do_not_route(): void
    {
        $this->actingAs($this->world->otherJudge);

        $this->get('/judging/'.$this->world->token().'/judging_list')->assertNotFound();
        $this->get('/judging_draw/'.$this->world->show->id.'/judging_list')->assertNotFound();
    }
}
