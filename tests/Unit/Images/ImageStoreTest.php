<?php

namespace Tests\Unit\Images;

use App\Services\Images\ImageStore;
use App\Services\Images\ImageUploadException;
use Illuminate\Http\UploadedFile;
use Imagick;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\images\ImageFixtures as F;
use Tests\TestCase;

/**
 * ImageStore against a temp root. Every input is generated at runtime. Nothing is written under
 * the real public/images store.
 */
class ImageStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sesf-imgtest-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);

        parent::tearDown();
    }

    #[Test]
    public function a_valid_png_is_stored_as_a_jpeg_at_the_legacy_path(): void
    {
        $path = $this->store(F::png(3, 2), 5);

        $this->assertSame('5/profile.jpg', $path);
        $this->assertFileExists($this->root.'/5/profile.jpg');
        $this->assertSame('image/jpeg', getimagesize($this->root.'/5/profile.jpg')['mime']);
    }

    #[Test]
    public function a_valid_jpeg_is_accepted(): void
    {
        $this->store(F::jpeg(6, 4), 7);

        $this->assertSame('image/jpeg', getimagesize($this->root.'/7/profile.jpg')['mime']);
    }

    #[Test]
    public function a_valid_webp_is_accepted_and_converted(): void
    {
        $this->store(F::webp(6, 4), 8);

        $this->assertSame('image/jpeg', getimagesize($this->root.'/8/profile.jpg')['mime']);
    }

    #[Test]
    public function an_animated_gif_is_accepted_and_only_its_first_frame_is_kept(): void
    {
        $this->store(F::animatedGif(4, 4), 9);

        $frames = new Imagick($this->root.'/9/profile.jpg');
        $this->assertSame(1, $frames->getNumberImages());
        $frames->destroy();
    }

    #[Test]
    public function a_text_file_named_jpg_is_rejected_by_content(): void
    {
        $this->expectException(ImageUploadException::class);
        $this->expectExceptionMessage('must be a JPEG, PNG, GIF or WebP');

        $this->store(F::textPretendingToBeJpeg(), 1, 'photo.jpg');
    }

    #[Test]
    public function a_svg_with_a_script_is_rejected_and_nothing_is_written(): void
    {
        $this->assertRejected(F::svgWithScript(), 2, ImageUploadException::class);
        $this->assertFileDoesNotExist($this->root.'/2/profile.jpg');
    }

    #[Test]
    public function a_png_with_an_appended_php_payload_is_stored_without_the_payload(): void
    {
        $this->store(F::pngWithPhpPayload(), 3);

        $bytes = file_get_contents($this->root.'/3/profile.jpg');
        $this->assertIsString($bytes);
        $this->assertStringNotContainsString('PAYLOAD_MARKER', $bytes);
        $this->assertStringNotContainsString('<?php', $bytes);
        $this->assertSame('image/jpeg', getimagesize($this->root.'/3/profile.jpg')['mime']);
    }

    #[Test]
    public function a_dimension_over_the_limit_is_rejected_before_decoding(): void
    {
        $this->assertRejected(F::png(ImageStore::MAX_DIMENSION + 1, 1), 4, ImageUploadException::class, 'larger than 8000');
        $this->assertRejected(F::png(1, ImageStore::MAX_DIMENSION + 1), 4, ImageUploadException::class, 'larger than 8000');
    }

    #[Test]
    public function a_dimension_at_the_limit_is_accepted(): void
    {
        $this->store(F::png(ImageStore::MAX_DIMENSION, 1), 4);

        $this->assertSame(ImageStore::MAX_DIMENSION, getimagesize($this->root.'/4/profile.jpg')[0]);
    }

    #[Test]
    public function a_file_over_12_mb_is_rejected_with_the_size_message(): void
    {
        $file = UploadedFile::fake()->create('huge.jpg', 12 * 1024 + 1);

        $this->expectException(ImageUploadException::class);
        $this->expectExceptionMessage('larger than 12 MB');

        $this->storer()->store($file, $this->root, 6);
    }

    #[Test]
    public function an_empty_upload_is_rejected(): void
    {
        $this->assertRejected('', 6, ImageUploadException::class);
    }

    #[Test]
    public function exif_orientation_is_applied_to_the_pixels(): void
    {
        // Stored 4 wide x 2 high with orientation 6 (rotate 90 clockwise): displays 2 x 4.
        $this->store(F::jpegWithOrientation(4, 2, 6), 10);

        $size = getimagesize($this->root.'/10/profile.jpg');
        $this->assertSame([2, 4], [$size[0], $size[1]]);
    }

    #[Test]
    public function metadata_such_as_exif_make_and_gps_is_stripped(): void
    {
        $source = F::jpegWithExif();
        $this->assertStringContainsString('SecretCam', $source, 'fixture must carry EXIF');

        $this->store($source, 11);

        $bytes = file_get_contents($this->root.'/11/profile.jpg');
        $this->assertIsString($bytes);
        $this->assertStringNotContainsString('SecretCam', $bytes);
        $this->assertStringNotContainsString('Exif', $bytes);
        $this->assertStringNotContainsString('GPS', $bytes);
    }

    #[Test]
    public function a_failed_store_leaves_the_previous_image_untouched_and_no_temp_file(): void
    {
        $this->store(F::png(2, 2, 'blue'), 12);
        $before = file_get_contents($this->root.'/12/profile.jpg');

        $this->assertRejected(F::truncatedPng(), 12, ImageUploadException::class);

        $this->assertSame($before, file_get_contents($this->root.'/12/profile.jpg'));
        $this->assertSame([], $this->leftoverTempFiles(12));
    }

    #[Test]
    public function an_unreadable_image_never_creates_a_partial_file(): void
    {
        $this->assertRejected(F::truncatedPng(), 13, ImageUploadException::class);

        $this->assertFileDoesNotExist($this->root.'/13/profile.jpg');
        $this->assertSame([], $this->leftoverTempFiles(13));
    }

    #[Test]
    public function a_write_failure_raises_a_save_error_and_leaves_nothing_behind(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Root ignores directory permissions.');
        }

        mkdir($this->root.'/14', 0755);
        chmod($this->root.'/14', 0555);

        try {
            $this->assertRejected(F::png(2, 2), 14, ImageUploadException::class, 'could not be saved');
            $this->assertSame([], array_values(array_diff(scandir($this->root.'/14'), ['.', '..'])));
        } finally {
            chmod($this->root.'/14', 0755);
        }
    }

    #[Test]
    public function older_variants_of_the_same_base_name_are_removed(): void
    {
        mkdir($this->root.'/15', 0755);
        file_put_contents($this->root.'/15/profile.png', 'old');
        file_put_contents($this->root.'/15/profile.gif', 'old');
        file_put_contents($this->root.'/15/other.png', 'keep');

        $this->store(F::png(2, 2), 15);

        $this->assertFileDoesNotExist($this->root.'/15/profile.png');
        $this->assertFileDoesNotExist($this->root.'/15/profile.gif');
        $this->assertFileExists($this->root.'/15/profile.jpg');
        $this->assertFileExists($this->root.'/15/other.png');
    }

    #[Test]
    public function the_directory_is_created_with_mode_0755(): void
    {
        $this->store(F::png(2, 2), 16);

        $this->assertSame(0755, fileperms($this->root.'/16') & 0777);
        $this->assertSame(0644, fileperms($this->root.'/16/profile.jpg') & 0777);
    }

    #[Test]
    public function delete_removes_every_variant_and_tolerates_a_missing_directory(): void
    {
        $this->store(F::png(2, 2), 17);
        file_put_contents($this->root.'/17/profile.png', 'old');

        $this->storer()->delete($this->root, 17);

        $this->assertSame([], array_values(array_diff(scandir($this->root.'/17'), ['.', '..'])));

        $this->storer()->delete($this->root, 999);
        $this->assertDirectoryDoesNotExist($this->root.'/999');
    }

    #[Test]
    public function a_non_positive_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->storer()->store(UploadedFile::fake()->createWithContent('a.jpg', F::png()), $this->root, 0);
    }

    #[Test]
    #[DataProvider('unsafeBaseNames')]
    public function a_base_name_that_is_not_a_plain_word_is_rejected(string $basename): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->storer()->store(UploadedFile::fake()->createWithContent('a.jpg', F::png()), $this->root, 1, $basename);
    }

    /** @return array<string, array{string}> */
    public static function unsafeBaseNames(): array
    {
        return [
            'traversal' => ['../../etc/passwd'],
            'slash' => ['a/b'],
            'dot' => ['profile.jpg'],
            'upper' => ['Profile'],
            'empty' => [''],
        ];
    }

    private function storer(): ImageStore
    {
        return new ImageStore;
    }

    private function store(string $bytes, int $id, string $clientName = 'photo.jpg'): string
    {
        return $this->storer()->store(UploadedFile::fake()->createWithContent($clientName, $bytes), $this->root, $id);
    }

    /**
     * @param  class-string<ImageUploadException>  $exception
     */
    private function assertRejected(string $bytes, int $id, string $exception, ?string $message = null): void
    {
        try {
            $this->store($bytes, $id);
        } catch (ImageUploadException $e) {
            $this->assertInstanceOf($exception, $e);
            if ($message !== null) {
                $this->assertStringContainsString($message, $e->getMessage());
            }

            return;
        }

        $this->fail('Expected the upload to be rejected.');
    }

    /** @return list<string> */
    private function leftoverTempFiles(int $id): array
    {
        $dir = $this->root.'/'.$id;
        if (! is_dir($dir)) {
            return [];
        }

        $names = scandir($dir) ?: [];

        return array_values(array_filter($names, fn (string $n): bool => str_ends_with($n, '.tmp')));
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        @chmod($path, 0755);
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
            self::removeTree($path.DIRECTORY_SEPARATOR.$name);
        }
        @rmdir($path);
    }
}
