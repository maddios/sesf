<?php

namespace Tests\Database\Api\Cars;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\images\ImageFixtures as F;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

/**
 * POST member/profile-image: the signed-in member's own photo.
 */
class MemberProfileImageTest extends TestCase
{
    use LoadsLegacySchema, UsesTempImageRoots;

    private const PATH = '/member/profile-image';

    #[Test]
    public function a_member_uploads_their_photo_and_the_has_image_flag_is_set(): void
    {
        $user = User::factory()->create(['has_image' => false]);

        $response = $this->actingAs($user)->post(self::PATH, $this->payload(F::png(3, 3)), $this->acceptJson());

        $response->assertOk();
        $this->assertSame('""', $response->getContent());
        $this->assertFileExists($this->imageTempRoot.'/members/'.$user->id.'/profile.jpg');
        $this->assertTrue($user->refresh()->has_image);
    }

    #[Test]
    public function a_user_id_in_the_request_is_ignored_and_the_signed_in_member_is_updated(): void
    {
        $user = User::factory()->create(['has_image' => false]);
        $other = User::factory()->create(['has_image' => false]);

        $this->actingAs($user)->post(self::PATH, $this->payload(F::png(), 'photo.png', ['user_id' => $other->id]), $this->acceptJson())
            ->assertOk();

        $this->assertFileExists($this->imageTempRoot.'/members/'.$user->id.'/profile.jpg');
        $this->assertFileDoesNotExist($this->imageTempRoot.'/members/'.$other->id.'/profile.jpg');
        $this->assertTrue($user->refresh()->has_image);
        $this->assertFalse($other->refresh()->has_image);
    }

    #[Test]
    public function a_fake_jpg_is_rejected_and_the_flag_is_not_set(): void
    {
        $user = User::factory()->create(['has_image' => false]);

        $response = $this->actingAs($user)->post(self::PATH, $this->payload(F::textPretendingToBeJpeg(), 'photo.jpg'), $this->acceptJson());

        $response->assertStatus(400);
        $this->assertIsArray($response->json());
        $this->assertFalse($user->refresh()->has_image);
        $this->assertDirectoryDoesNotExist($this->imageTempRoot.'/members/'.$user->id);
    }

    #[Test]
    public function a_request_without_a_file_is_a_400_with_a_message_array(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(self::PATH, [], $this->acceptJson())
            ->assertStatus(400)
            ->assertJsonIsArray();
    }

    #[Test]
    public function anonymous_requests_get_401_and_nothing_is_written(): void
    {
        $user = User::factory()->create(['has_image' => false]);

        $this->post(self::PATH, $this->payload(F::png()), $this->acceptJson())
            ->assertStatus(401)
            ->assertExactJson(['Auth error, not permitted']);

        $this->assertDirectoryDoesNotExist($this->imageTempRoot.'/members/'.$user->id);
    }

    #[Test]
    public function a_post_without_a_csrf_token_is_rejected_with_419(): void
    {
        // The test runner is treated as unit testing, which skips CSRF checks. Pretend to be
        // the app so the middleware runs.
        $this->app->instance('env', 'local');
        $user = User::factory()->create(['has_image' => false]);

        $this->actingAs($user)->post(self::PATH, $this->payload(F::png()), $this->acceptJson())
            ->assertStatus(419);
        $this->assertFalse($user->refresh()->has_image);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(string $bytes, string $name = 'photo.png', array $extra = []): array
    {
        return $extra + ['file' => UploadedFile::fake()->createWithContent($name, $bytes)];
    }

    /**
     * @return array<string, string>
     */
    private function acceptJson(): array
    {
        return ['Accept' => 'application/json'];
    }
}
