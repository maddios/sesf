<?php

namespace Tests\Database\Judging;

use App\Models\Judging\Answer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\images\ImageFixtures;

final class JudgingUploadTest extends JudgingTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/sesf-judging-img-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
        config(['sesf.image_root' => $this->root.'/']);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->root));
        $this->actingAs($this->world->judge);
    }

    private function photoDir(): string
    {
        return $this->root.'/judging/'.$this->world->entry->id.'/'.$this->world->q1->id;
    }

    /**
     * @return TestResponse<Response>
     */
    private function upload(string $bytes, string $name = 'photo.jpg'): TestResponse
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'judging-up');
        file_put_contents($path, $bytes);
        $file = new UploadedFile($path, $name, null, null, true);

        return $this->post('/judgingSubmit/'.$this->world->token(), ['data' => ['file_'.$this->world->q1->id => [$file]]], ['Accept' => 'application/json']);
    }

    public function test_an_upload_is_re_encoded_with_exif_stripped(): void
    {
        $field = 'file_'.$this->world->q1->id;

        $stored = $this->upload(ImageFixtures::jpegWithExif())->assertOk()->json('uploadedFiles.'.$field.'.0');

        $this->assertMatchesRegularExpression('/\A\d{10}_[0-9a-f]{13}\.jpg\z/', $stored['serverFile']);
        $this->assertSame('/images/judging/'.$this->world->entry->id.'/'.$this->world->q1->id.'/'.$stored['serverFile'], $stored['content']);

        $path = $this->photoDir().'/'.$stored['serverFile'];
        $this->assertFileExists($path);
        $exif = @exif_read_data($path);
        $this->assertFalse(is_array($exif) && isset($exif['Make']));

        $answer = Answer::query()->forRegistrationCar($this->world->entry->id)->sole();
        $this->assertNull($answer->data);
        $this->assertSame($this->world->q1->id, $answer->question_id);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notImages(): array
    {
        return [
            'text' => [ImageFixtures::textPretendingToBeJpeg()],
            'svg' => [ImageFixtures::svgWithScript()],
        ];
    }

    #[DataProvider('notImages')]
    public function test_files_that_are_not_images_are_rejected(string $bytes): void
    {
        $this->upload($bytes, 'x.jpg')
            ->assertStatus(400)
            ->assertJsonPath('errors.0', 'The file must be a JPEG, PNG, GIF or WebP image.');

        $this->assertSame([], is_dir($this->photoDir()) ? array_values(array_diff(scandir($this->photoDir()) ?: [], ['.', '..'])) : []);
        $this->assertSame(0, Answer::query()->count());
    }

    public function test_only_plain_photo_names_are_deleted(): void
    {
        mkdir($this->photoDir(), 0755, true);
        file_put_contents($this->photoDir().'/1700000000_abc.jpg', 'x');
        file_put_contents($this->root.'/keep.jpg', 'x');

        $this->submit(['delete_file_'.$this->world->q1->id => ['1700000000_abc.jpg', '../../../keep.jpg', '.htaccess']])->assertOk();

        $this->assertFileDoesNotExist($this->photoDir().'/1700000000_abc.jpg');
        $this->assertFileExists($this->root.'/keep.jpg');
    }

    public function test_stored_photos_are_listed_once_the_question_has_an_answer(): void
    {
        mkdir($this->photoDir(), 0755, true);
        file_put_contents($this->photoDir().'/1700000000_abc.jpg', 'x');
        $this->score($this->world->entry, [null]);

        $elements = $this->getJson('/judgingData/'.$this->world->token())->json('form.0.elements.0.elements');
        $file = collect((array) $elements)->firstWhere('name', 'file_'.$this->world->q1->id);

        $this->assertSame('1700000000_abc.jpg', $file['defaultValue'][0]['serverFile']);
    }
}
