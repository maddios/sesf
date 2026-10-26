<?php

namespace Tests\Database\Admin;

use App\Models\Car;
use App\Models\CarBody;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use App\Models\User;
use Database\Factories\CarBodyFactory;
use Database\Factories\CarFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\images\ImageFixtures as F;

/**
 * admin-api/cars: authorization, list (filters, show filter, sort, injection), edit (type and body kept apart),
 * confirm/unconfirm, delete conflicts, the profile photo and audit entries.
 */
class CarAdminTest extends AdminTestCase
{
    private const BASE = '/admin-api/cars';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sesf-car-img-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
        config(['sesf.car_image_root' => $this->root.'/']);

        $this->beforeApplicationDestroyed(function (): void {
            $this->removeTree($this->root);
        });
    }

    private function removeTree(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (glob($path.'/{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (! in_array(basename($entry), ['.', '..'], true)) {
                $this->removeTree($entry);
            }
        }
        if (is_dir($path)) {
            @rmdir($path);
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function actors(): array
    {
        return [
            'anonymous' => ['anonymous', 401],
            'member' => ['member', 403],
            'judge' => ['judge', 403],
            'admin' => ['admin', 200],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function editable(array $overrides = []): array
    {
        return array_merge([
            'user_id' => User::factory()->create()->id,
            'type_id' => CarFactory::TYPE_CAR,
            'body_id' => CarBodyFactory::E24,
            'year' => '1969',
            'model' => 'Camaro',
            'nickname' => 'Blue Bolt',
            'VIN' => '1234567890',
        ], $overrides);
    }

    #[Test]
    #[DataProvider('actors')]
    public function every_car_route_is_admin_only(string $who, int $status): void
    {
        $car = Car::factory()->create();
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);
        $expect = fn (int $ok) => $status === 200 ? $ok : $status;

        $as()->getJson(self::BASE)->assertStatus($expect(200));
        $as()->getJson(self::BASE.'/options')->assertStatus($expect(200));
        $as()->getJson(self::BASE.'/'.$car->id)->assertStatus($expect(200));
        $as()->putJson(self::BASE.'/'.$car->id, $this->editable())->assertStatus($expect(200));
        $as()->postJson(self::BASE.'/'.$car->id.'/unconfirm')->assertStatus($expect(200));
        $as()->postJson(self::BASE.'/'.$car->id.'/confirm')->assertStatus($expect(200));
        $as()->deleteJson(self::BASE.'/'.$car->id)->assertStatus($expect(204));

        if ($status !== 200) {
            $this->assertSame(1, Car::query()->count());
        }
    }

    #[Test]
    public function the_list_filters_by_show_body_type_owner_and_confirmation(): void
    {
        $show = CarShow::factory()->create();
        $entered = Car::factory()->create(['body_id' => CarBodyFactory::E24, 'model' => 'Entered']);
        Car::factory()->create(['body_id' => CarBodyFactory::E24, 'model' => 'Other']);
        Car::factory()->sold()->create(['model' => 'Sold']);
        Car::factory()->unconfirmed()->create(['model' => 'Pending']);
        $registration = ShowRegistration::factory()->create(['show_id' => $show->id]);
        ShowRegistrationCar::factory()->create(['car_id' => $entered->id, 'registration_id' => $registration->id]);
        $admin = $this->actingAs($this->admin());

        $this->assertSame(['Entered'], $this->models($admin->getJson(self::BASE.'?filter[show_id]='.$show->id)));
        $this->assertSame(['Sold'], $this->models($admin->getJson(self::BASE.'?filter[user_id][null]=1')));
        $this->assertSame(['Pending'], $this->models($admin->getJson(self::BASE.'?filter[is_confirmed]=0')));
        $this->assertSame(['Entered'], $this->models($admin->getJson(self::BASE.'?filter[model][like]=Entered')));
        $this->assertSame(['Entered'], $this->models($admin->getJson(self::BASE.'?filter[body_id][in]='.CarBodyFactory::E24.'&filter[model][like]=Entered')));
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<string|null>
     */
    private function models(TestResponse $response): array
    {
        $models = array_column($response->assertOk()->json('data'), 'model');
        sort($models);

        return $models;
    }

    #[Test]
    public function list_sorts_pages_and_rejects_unsafe_inputs(): void
    {
        Car::factory()->create(['model' => 'Zeta']);
        Car::factory()->create(['model' => 'Alpha']);
        $admin = $this->actingAs($this->admin());

        $admin->getJson(self::BASE.'?sort=model&direction=asc&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.model', 'Alpha');

        $admin->getJson(self::BASE.'?sort=model;drop table car')->assertStatus(422);
        $admin->getJson(self::BASE.'?sort=user_id')->assertStatus(422);
        $admin->getJson(self::BASE.'?filter[user_id][regex]=.*')->assertStatus(422);
        $admin->getJson(self::BASE.'?filter[password]=x')->assertStatus(422);
    }

    #[Test]
    public function the_list_keeps_type_and_body_as_separate_keys(): void
    {
        $car = Car::factory()->create(['body_id' => CarBodyFactory::E24, 'type_id' => CarFactory::TYPE_CAR]);
        $body = CarBody::query()->findOrFail(CarBodyFactory::E24);

        $this->actingAs($this->admin())->getJson(self::BASE.'/'.$car->id)
            ->assertOk()
            ->assertJsonPath('data.body_id', CarBodyFactory::E24)
            ->assertJsonPath('data.body_name', $body->name)
            ->assertJsonPath('data.type_id', CarFactory::TYPE_CAR)
            ->assertJsonPath('data.type_name', fn ($value) => is_string($value) && $value !== '');
    }

    #[Test]
    public function an_admin_edits_the_car_and_the_owner_is_set_through_the_guarded_column(): void
    {
        $car = Car::factory()->create();
        $owner = User::factory()->create();

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$car->id, $this->editable(['user_id' => $owner->id, 'model' => 'Mustang']))
            ->assertOk()
            ->assertJsonPath('data.user_id', $owner->id)
            ->assertJsonPath('data.model', 'Mustang');

        $car->refresh();
        $this->assertSame($owner->id, $car->user_id);
        $this->assertSame('Mustang', $car->model);
    }

    #[Test]
    public function a_car_can_be_sold_by_clearing_the_owner(): void
    {
        $car = Car::factory()->create();

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$car->id, $this->editable(['user_id' => null]))
            ->assertOk()
            ->assertJsonPath('data.user_id', null);
        $this->assertNull($car->refresh()->user_id);
    }

    #[Test]
    public function the_edit_form_validates_references_and_widths(): void
    {
        $car = Car::factory()->create();
        $admin = $this->actingAs($this->admin());

        $admin->putJson(self::BASE.'/'.$car->id, $this->editable(['type_id' => 999]))->assertJsonValidationErrors(['type_id']);
        $admin->putJson(self::BASE.'/'.$car->id, $this->editable(['body_id' => 999]))->assertJsonValidationErrors(['body_id']);
        $admin->putJson(self::BASE.'/'.$car->id, $this->editable(['user_id' => 999999]))->assertJsonValidationErrors(['user_id']);
        $admin->putJson(self::BASE.'/'.$car->id, $this->editable(['model' => str_repeat('m', 30)]))->assertOk();
        $admin->putJson(self::BASE.'/'.$car->id, $this->editable(['model' => str_repeat('m', 31)]))->assertJsonValidationErrors(['model']);
        $admin->putJson(self::BASE.'/'.$car->id, $this->editable(['year' => '123456']))->assertJsonValidationErrors(['year']);
        $admin->putJson(self::BASE.'/'.$car->id, $this->editable(['nickname' => str_repeat('n', 65)]))->assertJsonValidationErrors(['nickname']);
        $admin->putJson(self::BASE.'/'.$car->id, $this->editable(['VIN' => str_repeat('v', 21)]))->assertJsonValidationErrors(['VIN']);
    }

    #[Test]
    public function confirm_and_unconfirm_set_the_flag_and_are_audited(): void
    {
        $car = Car::factory()->unconfirmed()->create();
        $admin = $this->actingAs($this->admin());

        $admin->postJson(self::BASE.'/'.$car->id.'/confirm')->assertOk()->assertJsonPath('data.is_confirmed', true);
        $this->assertTrue($car->refresh()->is_confirmed);

        $admin->postJson(self::BASE.'/'.$car->id.'/unconfirm')->assertOk()->assertJsonPath('data.is_confirmed', false);
        $this->assertFalse($car->refresh()->is_confirmed);

        $actions = array_column($this->auditEntries(), 'action');
        $this->assertContains('car.confirm', $actions);
        $this->assertContains('car.unconfirm', $actions);
    }

    #[Test]
    public function a_car_entered_in_a_show_cannot_be_deleted(): void
    {
        $car = Car::factory()->create();
        $registration = ShowRegistration::factory()->create();
        ShowRegistrationCar::factory()->create(['car_id' => $car->id, 'registration_id' => $registration->id]);

        $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$car->id)
            ->assertStatus(409)
            ->assertJsonPath('references.registrations', 1);
        $this->assertTrue(Car::query()->whereKey($car->id)->exists());
    }

    #[Test]
    public function an_unused_car_is_deleted_and_audited(): void
    {
        $car = Car::factory()->create();

        $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$car->id)->assertNoContent();

        $this->assertFalse(Car::query()->whereKey($car->id)->exists());
        $this->assertContains('car.delete', array_column($this->auditEntries(), 'action'));
    }

    #[Test]
    public function an_admin_uploads_a_car_photo_to_the_profile_path(): void
    {
        $car = Car::factory()->create(['has_image' => false]);

        $this->actingAs($this->admin())->post(
            self::BASE.'/'.$car->id.'/image',
            ['file' => UploadedFile::fake()->createWithContent('car.png', F::png(5, 5))],
            self::JSON,
        )->assertOk()->assertJsonPath('data.has_image', true);

        $this->assertFileExists($this->root.'/'.$car->id.'/profile.jpg');
        $this->assertTrue($car->refresh()->has_image);
        $this->assertContains('car.image', array_column($this->auditEntries(), 'action'));
    }

    #[Test]
    public function a_file_that_is_not_an_image_is_refused_and_leaves_no_photo(): void
    {
        $car = Car::factory()->create(['has_image' => false]);

        $this->actingAs($this->admin())->post(
            self::BASE.'/'.$car->id.'/image',
            ['file' => UploadedFile::fake()->createWithContent('car.png', F::textPretendingToBeJpeg())],
            self::JSON,
        )->assertStatus(422)->assertJsonValidationErrors(['file']);

        $this->assertFileDoesNotExist($this->root.'/'.$car->id.'/profile.jpg');
        $this->assertFalse($car->refresh()->has_image);
    }

    #[Test]
    public function the_audit_entries_never_hold_the_nickname_or_vin(): void
    {
        $car = Car::factory()->create(['nickname' => 'Secret Nick', 'VIN' => 'SECRETVIN000000001']);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$car->id, $this->editable(['nickname' => 'Changed Nick', 'VIN' => 'NEWVIN00000000001']))->assertOk();

        $this->assertStringContainsString('"action":"car.update"', $this->auditText());
        $this->assertStringNotContainsString('Changed Nick', $this->auditText());
        $this->assertStringNotContainsString('NEWVIN00000000001', $this->auditText());
    }

    #[Test]
    public function the_options_list_the_car_types_and_bodies(): void
    {
        $this->actingAs($this->admin())->getJson(self::BASE.'/options')
            ->assertOk()
            ->assertJsonStructure(['data' => ['types' => [['id', 'label']], 'bodies' => [['id', 'label']]]]);
    }
}
