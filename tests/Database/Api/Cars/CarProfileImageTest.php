<?php

namespace Tests\Database\Api\Cars;

use App\Models\Car;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\images\ImageFixtures as F;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

/**
 * POST car/profile-image (multipart: file, car_id). Owner and administrator only.
 */
class CarProfileImageTest extends TestCase
{
    use LoadsLegacySchema, UsesTempImageRoots;

    private const PATH = '/car/profile-image';

    #[Test]
    public function the_owner_uploads_a_photo_and_the_car_is_flagged_as_having_one(): void
    {
        [$user, $car] = $this->ownedCar();

        $response = $this->actingAs($user)->post(self::PATH, $this->payload($car, F::png(3, 3)), $this->acceptJson());

        $response->assertOk();
        $this->assertSame('""', $response->getContent());
        $this->assertFileExists($this->imageTempRoot.'/cars/'.$car->id.'/profile.jpg');
        $this->assertTrue($car->refresh()->has_image);
    }

    #[Test]
    public function a_second_upload_replaces_the_first_and_removes_other_variants(): void
    {
        [$user, $car] = $this->ownedCar();
        mkdir($this->imageTempRoot.'/cars/'.$car->id, 0755, true);
        file_put_contents($this->imageTempRoot.'/cars/'.$car->id.'/profile.png', 'old');

        $this->actingAs($user)->post(self::PATH, $this->payload($car, F::jpeg(2, 2)), $this->acceptJson())->assertOk();

        $this->assertFileDoesNotExist($this->imageTempRoot.'/cars/'.$car->id.'/profile.png');
        $this->assertSame('image/jpeg', getimagesize($this->imageTempRoot.'/cars/'.$car->id.'/profile.jpg')['mime']);
    }

    #[Test]
    public function an_administrator_can_upload_to_any_car(): void
    {
        [, $car] = $this->ownedCar();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(self::PATH, $this->payload($car, F::png()), $this->acceptJson())->assertOk();

        $this->assertTrue($car->refresh()->has_image);
    }

    #[Test]
    public function another_member_gets_404_and_nothing_is_written(): void
    {
        [, $car] = $this->ownedCar();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->post(self::PATH, $this->payload($car, F::png()), $this->acceptJson())
            ->assertStatus(404)
            ->assertExactJson(['Car not found']);

        $this->assertDirectoryDoesNotExist($this->imageTempRoot.'/cars/'.$car->id);
        $this->assertFalse($car->refresh()->has_image);
    }

    #[Test]
    public function an_unknown_or_missing_car_is_404_json_not_a_crash(): void
    {
        [$user] = $this->ownedCar();
        $file = UploadedFile::fake()->createWithContent('a.png', F::png());

        $this->actingAs($user)->post(self::PATH, ['car_id' => 999999, 'file' => $file], $this->acceptJson())
            ->assertStatus(404)->assertExactJson(['Car not found']);
        $this->actingAs($user)->post(self::PATH, ['car_id' => 'abc', 'file' => $file], $this->acceptJson())
            ->assertStatus(404);
        $this->actingAs($user)->post(self::PATH, ['file' => $file], $this->acceptJson())
            ->assertStatus(404);
    }

    #[Test]
    public function a_text_file_named_jpg_is_rejected_with_the_legacy_message_array(): void
    {
        [$user, $car] = $this->ownedCar();

        $response = $this->actingAs($user)->post(self::PATH, $this->payload($car, F::textPretendingToBeJpeg(), 'photo.jpg'), $this->acceptJson());

        $response->assertStatus(400);
        $this->assertIsArray($response->json());
        $this->assertStringContainsString('JPEG, PNG, GIF or WebP', $response->json()[0]);
        $this->assertFalse($car->refresh()->has_image);
    }

    #[Test]
    public function an_svg_with_a_script_is_rejected(): void
    {
        [$user, $car] = $this->ownedCar();

        $this->actingAs($user)->post(self::PATH, $this->payload($car, F::svgWithScript(), 'x.svg'), $this->acceptJson())
            ->assertStatus(400);
        $this->assertDirectoryDoesNotExist($this->imageTempRoot.'/cars/'.$car->id);
    }

    #[Test]
    public function a_png_with_an_appended_php_payload_is_stored_without_it(): void
    {
        [$user, $car] = $this->ownedCar();

        $this->actingAs($user)->post(self::PATH, $this->payload($car, F::pngWithPhpPayload(), 'x.png'), $this->acceptJson())
            ->assertOk();

        $this->assertStringNotContainsString(
            'PAYLOAD_MARKER',
            file_get_contents($this->imageTempRoot.'/cars/'.$car->id.'/profile.jpg'),
        );
    }

    #[Test]
    public function an_image_over_the_dimension_limit_is_rejected(): void
    {
        [$user, $car] = $this->ownedCar();

        $this->actingAs($user)->post(self::PATH, $this->payload($car, F::png(8001, 1)), $this->acceptJson())
            ->assertStatus(400)
            ->assertJsonFragment(['The image is larger than 8000 x 8000 pixels.']);
    }

    #[Test]
    public function a_file_over_12_mb_is_rejected(): void
    {
        [$user, $car] = $this->ownedCar();

        $this->actingAs($user)->post(self::PATH, [
            'car_id' => $car->id,
            'file' => UploadedFile::fake()->create('big.jpg', 12 * 1024 + 1),
        ], $this->acceptJson())->assertStatus(400);
    }

    #[Test]
    public function a_request_without_a_file_is_rejected_with_400(): void
    {
        [$user, $car] = $this->ownedCar();

        $this->actingAs($user)->post(self::PATH, ['car_id' => $car->id], $this->acceptJson())
            ->assertStatus(400)
            ->assertJsonIsArray();
    }

    #[Test]
    public function anonymous_requests_get_401_and_nothing_is_written(): void
    {
        [, $car] = $this->ownedCar();

        $this->post(self::PATH, $this->payload($car, F::png()), $this->acceptJson())
            ->assertStatus(401)
            ->assertExactJson(['Auth error, not permitted']);

        $this->assertDirectoryDoesNotExist($this->imageTempRoot.'/cars/'.$car->id);
    }

    #[Test]
    public function uploads_are_throttled_to_20_a_minute(): void
    {
        [$user, $car] = $this->ownedCar();

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($user)->post(self::PATH, $this->payload($car, F::png()), $this->acceptJson())->assertOk();
        }

        $this->actingAs($user)->post(self::PATH, $this->payload($car, F::png()), $this->acceptJson())
            ->assertStatus(429);
    }

    #[Test]
    public function a_post_without_a_csrf_token_is_rejected_with_419(): void
    {
        // The test runner is treated as unit testing, which skips CSRF checks. Pretend to be
        // the app so the middleware runs.
        $this->app->instance('env', 'local');
        [$user, $car] = $this->ownedCar();

        $this->actingAs($user)->post(self::PATH, $this->payload($car, F::png()), $this->acceptJson())
            ->assertStatus(419);
        $this->assertFalse($car->refresh()->has_image);
    }

    /**
     * @return array{0: User, 1: Car}
     */
    private function ownedCar(): array
    {
        $user = User::factory()->create();

        return [$user, Car::factory()->create(['user_id' => $user->id])];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Car $car, string $bytes, string $name = 'photo.png'): array
    {
        return [
            'car_id' => $car->id,
            'file' => UploadedFile::fake()->createWithContent($name, $bytes),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function acceptJson(): array
    {
        return ['Accept' => 'application/json'];
    }
}
