<?php

namespace Tests\Database\Api\Account;

use App\Models\UserPreferences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class TutorialTest extends AccountTestCase
{
    #[Test]
    public function anonymous_requests_get_401(): void
    {
        $user = $this->makeUser();

        $this->getJson('/tutorial/tutorial?id='.$user->id)->assertUnauthorized();
        $this->postJson('/tutorial/tutorial', ['show_login_welcome' => false])->assertUnauthorized();
    }

    #[Test]
    public function the_member_reads_their_own_flag_and_row_id(): void
    {
        $user = $this->makeUser();
        $prefs = UserPreferences::query()->where('user_id', $user->id)->firstOrFail();

        $this->actingAs($user)->getJson('/tutorial/tutorial')
            ->assertOk()
            ->assertExactJson(['show_login_welcome' => true, 'id' => $prefs->id]);
    }

    #[Test]
    public function reading_another_members_id_returns_only_the_callers_own_row(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $otherPrefs = UserPreferences::query()->where('user_id', $other->id)->firstOrFail();
        $myPrefs = UserPreferences::query()->where('user_id', $me->id)->firstOrFail();

        $this->actingAs($me)->getJson('/tutorial/tutorial?id='.$otherPrefs->id)
            ->assertOk()
            ->assertJsonPath('id', $myPrefs->id);
    }

    #[Test]
    public function the_member_turns_the_welcome_off_for_themselves(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/tutorial/tutorial', ['show_login_welcome' => false])
            ->assertOk()
            ->assertContent('""');

        $this->assertFalse(UserPreferences::query()->where('user_id', $user->id)->firstOrFail()->show_login_welcome);
    }

    #[Test]
    public function an_id_of_another_members_row_cannot_be_changed(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $otherPrefs = UserPreferences::query()->where('user_id', $other->id)->firstOrFail();

        $this->actingAs($me)->postJson('/tutorial/tutorial', ['id' => $otherPrefs->id, 'show_login_welcome' => false])
            ->assertOk();

        $this->assertTrue($otherPrefs->fresh()->show_login_welcome);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidFlags(): array
    {
        return [
            'missing' => [null],
            'not a boolean' => ['sometimes'],
        ];
    }

    #[Test]
    #[DataProvider('invalidFlags')]
    public function an_invalid_flag_is_refused(mixed $value): void
    {
        $user = $this->makeUser();
        $body = $value === null ? [] : ['show_login_welcome' => $value];

        $this->actingAs($user)->postJson('/tutorial/tutorial', $body)->assertStatus(400);
        $this->assertTrue(UserPreferences::query()->where('user_id', $user->id)->firstOrFail()->show_login_welcome);
    }

    #[Test]
    public function a_post_without_a_csrf_token_gets_419(): void
    {
        $this->app->instance('env', 'local');
        $user = $this->makeUser();

        $this->actingAs($user)->withHeader('Accept', 'application/json')
            ->post('/tutorial/tutorial', ['show_login_welcome' => false])
            ->assertStatus(419);
    }
}
