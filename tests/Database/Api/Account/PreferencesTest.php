<?php

namespace Tests\Database\Api\Account;

use App\Models\UserPreferences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class PreferencesTest extends AccountTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function flags(array $overrides = []): array
    {
        return array_merge([
            'allow_system_email' => false,
            'allow_future_show_notifications' => false,
            'allow_private_message' => false,
        ], $overrides);
    }

    #[Test]
    public function anonymous_requests_get_401(): void
    {
        $this->getJson('/member/private-preferences')->assertUnauthorized();
        $this->postJson('/member/private-preferences', $this->flags())->assertUnauthorized();
    }

    #[Test]
    public function the_member_reads_their_own_preferences(): void
    {
        $user = $this->makeUser();
        $prefs = UserPreferences::query()->where('user_id', $user->id)->firstOrFail();

        $this->actingAs($user)->getJson('/member/private-preferences')
            ->assertOk()
            ->assertJsonPath('id', $prefs->id)
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('allow_private_message', true);
    }

    #[Test]
    public function the_member_updates_their_own_flags_and_gets_the_saved_row_back(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/member/private-preferences', $this->flags())
            ->assertOk()
            ->assertJsonPath('allow_private_message', false)
            ->assertJsonPath('allow_system_email', false);

        $prefs = UserPreferences::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertFalse($prefs->allow_private_message);
        $this->assertFalse($prefs->allow_future_show_notifications);
    }

    #[Test]
    public function an_id_of_another_members_row_cannot_be_updated(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $otherPrefs = UserPreferences::query()->where('user_id', $other->id)->firstOrFail();

        $this->actingAs($me)->postJson('/member/private-preferences', $this->flags(['id' => $otherPrefs->id]))->assertOk();

        $this->assertTrue($otherPrefs->fresh()->allow_private_message);
        $this->assertFalse(UserPreferences::query()->where('user_id', $me->id)->firstOrFail()->allow_private_message);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function invalidFlags(): array
    {
        return [
            'missing' => ['allow_private_message', null],
            'not a boolean' => ['allow_private_message', 'maybe'],
            'out of range' => ['allow_system_email', 2],
        ];
    }

    #[Test]
    #[DataProvider('invalidFlags')]
    public function invalid_flags_are_rejected_and_nothing_changes(string $field, mixed $value): void
    {
        $user = $this->makeUser();
        $body = $this->flags([$field => $value]);
        if ($value === null) {
            unset($body[$field]);
        }

        $this->actingAs($user)->postJson('/member/private-preferences', $body)
            ->assertStatus(400)
            ->assertJsonIsArray();

        $this->assertTrue(UserPreferences::query()->where('user_id', $user->id)->firstOrFail()->allow_private_message);
    }
}
