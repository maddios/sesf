<?php

namespace Tests\Database\Api;

use App\Models\Car;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use App\Models\User;
use App\Models\UserPreferences;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

class MemberProfileReadTest extends TestCase
{
    use LoadsLegacySchema;

    /** @var list<string> */
    private const PUBLIC_INFO_KEYS = ['id', 'has_image', 'profileURL', 'imageURL', 'firstNameLastInitial', 'fullName', 'cityState'];

    /** @var list<string> */
    private const FORBIDDEN_TO_OTHERS = [
        'email', 'phone', 'phone2', 'address1', 'address2', 'zip', 'lattitude', 'longitude',
        'remember_token', 'password', 'type_id', 'is_admin', 'date_registered', 'location_id',
    ];

    /** @var list<string> */
    private const EVENT_KEYS = ['id', 'show_id', 'showName', 'shortDate', 'profileURL', 'carShow', 'cars'];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function profile_has_exact_top_level_keys(): void
    {
        $member = User::factory()->create();

        $profile = $this->getJson('/member/profile?user_id='.$member->id)->assertOk()->json();

        $this->assertSame(['is_private', 'member_info', 'car_list', 'event_list', 'member_preferences'], array_keys($profile));
        $this->assertSame(['num_cars', 'cars'], array_keys($profile['car_list']));
        $this->assertSame(['num_events', 'events'], array_keys($profile['event_list']));
        $this->assertSame(['allow_private_message'], array_keys($profile['member_preferences']));
        $this->assertFalse($profile['is_private']);
    }

    #[Test]
    public function guests_get_the_public_member_info_only(): void
    {
        $member = User::factory()->create(['first' => 'Hal', 'last' => 'Mercer', 'state' => 'NC', 'city' => 'Charlotte']);

        $info = $this->getJson('/member/member-info?user_id='.$member->id)->assertOk()->json();

        $this->assertSame(self::PUBLIC_INFO_KEYS, array_keys($info));
        $this->assertSame('Hal M', $info['firstNameLastInitial']);
        $this->assertSame('NC', $info['cityState']);
        $this->assertNoKeys(self::FORBIDDEN_TO_OTHERS, $info);
    }

    #[Test]
    public function other_members_get_the_public_member_info_only(): void
    {
        $member = User::factory()->create(['first' => 'Hal', 'last' => 'Mercer', 'state' => 'NC', 'city' => 'Charlotte']);
        $viewer = User::factory()->create();

        $info = $this->actingAs($viewer)->getJson('/member/member-info?user_id='.$member->id)->assertOk()->json();

        $this->assertSame(array_merge(self::PUBLIC_INFO_KEYS, ['first', 'last']), array_keys($info));
        $this->assertSame('Hal Mercer', $info['fullName']);
        $this->assertSame('Charlotte, NC', $info['cityState']);
        $this->assertNoKeys(self::FORBIDDEN_TO_OTHERS, $info);
    }

    #[Test]
    public function the_subject_sees_the_full_own_record_without_secrets(): void
    {
        $member = User::factory()->create(['email' => 'self@example.test']);

        $info = $this->actingAs($member)->getJson('/member/member-info?user_id='.$member->id)->assertOk()->json();

        $this->assertSame('self@example.test', $info['email']);
        $this->assertArrayHasKey('phone', $info);
        $this->assertArrayNotHasKey('password', $info);
        $this->assertArrayNotHasKey('remember_token', $info);
        $this->actingAs($member)->getJson('/member/profile?user_id='.$member->id)->assertJsonPath('is_private', true);
    }

    #[Test]
    public function admins_see_the_full_record_of_another_member_without_secrets(): void
    {
        $member = User::factory()->create(['email' => 'target@example.test']);
        $admin = User::factory()->admin()->create();

        $info = $this->actingAs($admin)->getJson('/member/member-info?user_id='.$member->id)->assertOk()->json();

        $this->assertSame('target@example.test', $info['email']);
        $this->assertArrayNotHasKey('password', $info);
        $this->assertArrayNotHasKey('remember_token', $info);
    }

    #[Test]
    public function car_list_counts_all_cars_and_returns_the_three_newest(): void
    {
        $member = User::factory()->create();
        foreach (range(1, 4) as $i) {
            Car::factory()->create(['user_id' => $member->id, 'model' => 'Model '.$i, 'created_at' => Carbon::parse('2026-01-0'.$i.' 10:00:00')]);
        }
        Car::factory()->sold()->create(['model' => 'Sold']);

        $this->getJson('/member/car-list?user_id='.$member->id)
            ->assertOk()
            ->assertJsonPath('num_cars', 4)
            ->assertJsonCount(3, 'cars')
            ->assertJsonPath('cars.0.model', 'Model 4');
    }

    #[Test]
    public function member_cars_endpoint_reads_user_id(): void
    {
        $member = User::factory()->create();
        Car::factory()->create(['user_id' => $member->id, 'model' => 'Mine']);
        Car::factory()->create(['model' => 'Someone else']);

        $cars = $this->getJson('/member/cars?user_id='.$member->id)->assertOk()->json();

        $this->assertCount(1, $cars);
        $this->assertSame(['id', 'has_image', 'name', 'nickname', 'year', 'model', 'body', 'type_id', 'profileURL', 'imageURL', 'shows'], array_keys($cars[0]));
        $this->assertSame('Mine', $cars[0]['model']);
    }

    #[Test]
    public function attended_events_read_user_id_skip_cancelled_and_sort_newest_first(): void
    {
        $member = User::factory()->create();
        $older = CarShow::factory()->create(['name' => 'Older', 'date_start' => '2025-05-01', 'date_end' => '2025-05-02']);
        $newer = CarShow::factory()->create(['name' => 'Newer', 'date_start' => '2026-05-01', 'date_end' => '2026-05-02']);
        ShowRegistration::factory()->create(['show_id' => $older->id, 'user_id' => $member->id]);
        ShowRegistration::factory()->create(['show_id' => $newer->id, 'user_id' => $member->id]);
        ShowRegistration::factory()->cancelled()->create(['show_id' => CarShow::factory()->create()->id, 'user_id' => $member->id]);

        $events = $this->getJson('/member/attended-events?user_id='.$member->id)->assertOk()->json();

        $this->assertSame(['num_events', 'events'], array_keys($events));
        $this->assertSame(2, $events['num_events']);
        $this->assertSame(['Newer', 'Older'], array_column(array_column($events['events'], 'carShow'), 'name'));
        $this->assertSame(self::EVENT_KEYS, array_keys($events['events'][0]));
        $this->assertNoKeys(['amount_owed', 'amount_received', 'invoice_id', 'is_paid', 'waiver_id', 'payment_method_id', 'user_id', 'discount'], $events['events'][0]);
    }

    #[Test]
    public function attended_event_cars_are_hidden_from_others_when_the_owner_opted_out(): void
    {
        $member = User::factory()->create();
        UserPreferences::query()->where('user_id', $member->id)->update(['show_car_on_attendees_page' => 0]);
        $show = CarShow::factory()->create();
        $registration = ShowRegistration::factory()->create(['show_id' => $show->id, 'user_id' => $member->id]);
        $car = Car::factory()->create(['user_id' => $member->id]);
        ShowRegistrationCar::factory()->create(['car_id' => $car->id, 'registration_id' => $registration->id]);

        $this->getJson('/member/attended-events?user_id='.$member->id)
            ->assertOk()
            ->assertJsonPath('events.0.cars', []);

        $this->actingAs($member)
            ->getJson('/member/attended-events?user_id='.$member->id)
            ->assertOk()
            ->assertJsonCount(1, 'events.0.cars');
    }

    #[Test]
    public function member_preferences_default_to_zero_without_a_row_and_follow_the_setting(): void
    {
        $noRow = User::factory()->withoutPreferences()->create();
        $allowed = User::factory()->create();
        $optedOut = User::factory()->create();
        UserPreferences::query()->where('user_id', $optedOut->id)->update(['allow_private_message' => 0]);

        $this->getJson('/member/member-preferences?user_id='.$noRow->id)->assertOk()->assertExactJson(['allow_private_message' => 0]);
        $this->getJson('/member/member-preferences?user_id='.$allowed->id)->assertOk()->assertExactJson(['allow_private_message' => 1]);
        $this->getJson('/member/member-preferences?user_id='.$optedOut->id)->assertOk()->assertExactJson(['allow_private_message' => 0]);
    }

    #[Test]
    public function unknown_missing_invalid_and_deleted_members_get_404_json(): void
    {
        $deleted = User::factory()->deleted()->create();

        foreach (['/member/profile?user_id=999999', '/member/profile', '/member/profile?user_id=abc', "/member/profile?user_id={$deleted->id}"] as $url) {
            $this->getJson($url)->assertNotFound()->assertJsonStructure(['message']);
        }
    }

    #[Test]
    public function profile_with_a_deleted_member_gets_404_for_every_member_endpoint(): void
    {
        $deleted = User::factory()->deleted()->create();

        foreach (['member-info', 'member-preferences', 'cars', 'car-list', 'attended-events'] as $endpoint) {
            $this->getJson('/member/'.$endpoint.'?user_id='.$deleted->id)->assertNotFound();
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $payload
     */
    private function assertNoKeys(array $keys, array $payload): void
    {
        foreach ($keys as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }
    }

    #[Test]
    public function a_car_marked_sold_leaves_the_member_garage_and_car_list(): void
    {
        $member = User::factory()->create();
        $kept = Car::factory()->create(['user_id' => $member->id, 'model' => 'Kept']);
        $sold = Car::factory()->create(['user_id' => $member->id, 'model' => 'Sold']);

        // Legacy postSellCar: the owner is cleared and the car keeps its show history.
        $sold->forceFill(['user_id' => null])->save();

        $this->getJson('/member/cars?user_id='.$member->id)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $kept->id);

        $this->getJson('/member/car-list?user_id='.$member->id)
            ->assertOk()
            ->assertJsonPath('num_cars', 1)
            ->assertJsonCount(1, 'cars')
            ->assertJsonMissing(['Sold']);
    }
}
