<?php

namespace Tests\Database\Auth;

use App\Enums\UserType;
use App\Models\User;
use App\Models\UserPreferences;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

class AuthenticateTest extends TestCase
{
    use LoadsLegacySchema;

    #[Test]
    public function the_session_probe_returns_an_empty_401_for_a_guest(): void
    {
        $response = $this->post('/login/authenticate');

        $response->assertStatus(401);
        $this->assertSame('', $response->getContent());
    }

    #[Test]
    public function the_session_probe_returns_the_member_payload_for_a_signed_in_user(): void
    {
        $user = User::factory()->create(['first' => 'Eugene', 'last' => 'Smith']);
        $this->actingAs($user, 'web');

        $response = $this->post('/login/authenticate');

        $response->assertOk()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('first_name', 'Eugene')
            ->assertJsonPath('profileURL', $user->profileURL)
            ->assertJsonPath('fullAddress', $user->fullAddress);
    }

    #[Test]
    public function the_payload_has_exactly_the_legacy_keys_and_no_credentials(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);
        $this->actingAs($user, 'web');

        $json = $this->post('/login/authenticate')->json();

        $this->assertIsArray($json);
        $this->assertEqualsCanonicalizing([
            'user_id', 'user_level', 'type_id', 'email', 'first_name',
            'profileURL', 'slideOutName', 'fullAddress',
        ], array_keys($json));
        $this->assertArrayNotHasKey('password', $json);
        $this->assertArrayNotHasKey('remember_token', $json);
    }

    #[Test]
    public function user_level_and_type_id_mirror_the_legacy_type_id(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user, 'web');

        $json = $this->post('/login/authenticate')->json();

        $this->assertIsArray($json);
        $this->assertSame(UserType::Administrator->value, $json['user_level']);
        $this->assertSame(UserType::Administrator->value, $json['type_id']);
    }

    #[Test]
    public function slide_out_name_is_1_when_the_login_welcome_preference_is_on(): void
    {
        $user = User::factory()->create();
        UserPreferences::query()->where('user_id', $user->id)->update(['show_login_welcome' => true]);
        $this->actingAs($user, 'web');

        $this->assertSame(1, $this->post('/login/authenticate')->json('slideOutName'));
    }

    #[Test]
    public function slide_out_name_is_0_when_the_login_welcome_preference_is_off(): void
    {
        $user = User::factory()->create();
        UserPreferences::query()->where('user_id', $user->id)->update(['show_login_welcome' => false]);
        $this->actingAs($user, 'web');

        $this->assertSame(0, $this->post('/login/authenticate')->json('slideOutName'));
    }

    #[Test]
    public function slide_out_name_is_0_when_the_member_has_no_preferences_row(): void
    {
        $user = User::factory()->create();
        UserPreferences::query()->where('user_id', $user->id)->delete();
        $this->actingAs($user, 'web');

        $this->assertSame(0, $this->post('/login/authenticate')->json('slideOutName'));
    }
}
