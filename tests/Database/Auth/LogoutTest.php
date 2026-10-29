<?php

namespace Tests\Database\Auth;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use LoadsLegacySchema;

    #[Test]
    public function a_json_logout_returns_the_message_and_ends_the_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $this->postJson('/login/logout')
            ->assertOk()
            ->assertExactJson(['message' => 'Logged out']);

        $this->assertGuest('web');
    }

    #[Test]
    public function a_navigation_logout_redirects_to_the_spa_login_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $this->get('/login/logout')->assertRedirect('/#/login');

        $this->assertGuest('web');
    }

    #[Test]
    public function a_local_redirect_path_is_followed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $this->get('/login/logout?redirect=/cars/12')->assertRedirect('/cars/12');
    }

    #[Test]
    #[DataProvider('unsafeRedirectTargets')]
    public function unsafe_redirect_targets_fall_back_to_the_spa_login_page(string $target): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $this->get('/login/logout?redirect='.rawurlencode($target))->assertRedirect('/#/login');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeRedirectTargets(): array
    {
        return [
            'protocol relative' => ['//evil.com'],
            'backslash host' => ['/\\evil.com'],
            'mixed slashes' => ['/\\/evil.com'],
            'absolute https' => ['https://evil.com'],
            'javascript scheme' => ['javascript:alert(1)'],
            'tab inside prefix' => ["/\t/evil.com"],
            'newline inside prefix' => ["/\n/evil.com"],
            'empty' => [''],
            'relative path' => ['cars'],
        ];
    }

    #[Test]
    public function a_post_logout_without_a_csrf_token_is_rejected_with_419(): void
    {
        $this->app->instance('env', 'local');
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $this->post('/login/logout')->assertStatus(419);
    }
}
