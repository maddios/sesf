<?php

namespace Tests\Database\Api;

use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

class MemberDirectoryTest extends TestCase
{
    use LoadsLegacySchema;

    /** @var list<string> */
    private const GUEST_ROW_KEYS = ['id', 'has_image', 'profileURL', 'imageURL', 'firstNameLastInitial', 'fullName', 'cityState'];

    /** @var list<string> */
    private const MEMBER_ROW_KEYS = ['id', 'has_image', 'profileURL', 'imageURL', 'firstNameLastInitial', 'fullName', 'cityState', 'first', 'last'];

    /** @var list<string> */
    private const FORBIDDEN = [
        'email', 'phone', 'phone2', 'address1', 'address2', 'zip', 'city', 'state', 'lattitude', 'longitude',
        'remember_token', 'password', 'type_id', 'is_admin', 'date_registered', 'is_confirmed', 'location_id',
    ];

    #[Test]
    public function list_filters_offer_attended_shows_and_name(): void
    {
        $show = CarShow::factory()->create(['name' => 'Attended Show']);
        ShowRegistration::factory()->create(['show_id' => $show->id]);

        $filters = $this->getJson('/members/list-filters?show_id='.$show->id.'&name=Ann')->assertOk()->json();

        $this->assertSame(['type', 'name', 'label', 'data', 'value'], array_keys($filters[0]));
        $this->assertSame('show_id', $filters[0]['name']);
        $this->assertSame([['id' => $show->id, 'name' => 'Attended Show']], $filters[0]['data']);
        $this->assertSame((string) $show->id, $filters[0]['value']);
        $this->assertSame(['type', 'name', 'label', 'value'], array_keys($filters[1]));
        $this->assertSame('Ann', $filters[1]['value']);
    }

    #[Test]
    public function guests_get_masked_rows_with_no_contact_or_secret_fields(): void
    {
        $member = User::factory()->create(['first' => 'Gina', 'last' => 'Zebrawood', 'city' => 'Orlando', 'state' => 'FL']);

        $response = $this->getJson('/members/users?limit=50')->assertOk();
        $row = $this->rowFor($response->json('data'), $member);

        $this->assertSame(self::GUEST_ROW_KEYS, array_keys($row));
        $this->assertSame('Gina Z', $row['firstNameLastInitial']);
        $this->assertSame('Gina Z', $row['fullName']);
        $this->assertSame('FL', $row['cityState']);
        $this->assertNoForbiddenKeys($response->json('data'));
    }

    #[Test]
    public function logged_in_members_get_full_names_and_city_state(): void
    {
        $viewer = User::factory()->create();
        $member = User::factory()->create(['first' => 'Gina', 'last' => 'Zebrawood', 'city' => 'Orlando', 'state' => 'FL']);

        $response = $this->actingAs($viewer)->getJson('/members/users?limit=100')->assertOk();
        $row = $this->rowFor($response->json('data'), $member);

        $this->assertSame(self::MEMBER_ROW_KEYS, array_keys($row));
        $this->assertSame('Gina Zebrawood', $row['fullName']);
        $this->assertSame('Orlando, FL', $row['cityState']);
        $this->assertSame('Gina', $row['first']);
        $this->assertSame('Zebrawood', $row['last']);
        $this->assertNoForbiddenKeys($response->json('data'));
    }

    #[Test]
    public function pagination_shape_and_limits(): void
    {
        User::factory()->count(30)->create();

        $page = $this->getJson('/members/users?limit=10&page=2')->assertOk()->json();

        $this->assertSame(['last', 'page', 'perPage', 'data', 'total'], array_keys($page));
        $this->assertSame(3, $page['last']);
        $this->assertSame(2, $page['page']);
        $this->assertSame(10, $page['perPage']);
        $this->assertSame(30, $page['total']);
        $this->assertCount(10, $page['data']);

        $capped = $this->getJson('/members/users?limit=1000')->assertOk()->json();
        $this->assertSame(100, $capped['perPage']);

        $default = $this->getJson('/members/users')->assertOk()->json();
        $this->assertSame(15, $default['perPage']);
    }

    #[Test]
    public function soft_deleted_members_never_appear(): void
    {
        $gone = User::factory()->deleted()->create(['first' => 'Ghost', 'last' => 'Removed']);

        $response = $this->actingAs(User::factory()->create())->getJson('/members/users?name=Ghost')->assertOk();

        $this->assertSame(0, $response->json('total'));
        $this->assertNull($this->findRow($response->json('data'), $gone));
    }

    #[Test]
    public function name_filter_searches_last_names_only_for_members(): void
    {
        $member = User::factory()->create(['first' => 'Quinn', 'last' => 'Yellowstone']);

        $this->getJson('/members/users?name=Yellowstone')->assertOk()->assertJsonPath('total', 0);

        $this->actingAs(User::factory()->create())
            ->getJson('/members/users?name=Yellowstone')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $member->id);
    }

    #[Test]
    public function name_filter_escapes_like_wildcards(): void
    {
        User::factory()->create(['first' => 'Plain', 'last' => 'Person']);

        $this->getJson('/members/users?name=%25')->assertOk()->assertJsonPath('total', 0);
    }

    #[Test]
    public function show_filter_lists_only_attendees_of_that_show(): void
    {
        $show = CarShow::factory()->create();
        $attendee = User::factory()->create();
        ShowRegistration::factory()->create(['show_id' => $show->id, 'user_id' => $attendee->id]);
        User::factory()->create();

        $this->getJson('/members/users?show_id='.$show->id)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $attendee->id);
    }

    #[Test]
    public function list_of_25_rows_uses_a_bounded_number_of_queries(): void
    {
        User::factory()->count(25)->create();

        DB::enableQueryLog();
        $this->getJson('/members/users?limit=25')->assertOk()->assertJsonCount(25, 'data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, count($queries), 'Too many queries for a 25-row page.');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function findRow(array $rows, User $user): ?array
    {
        foreach ($rows as $row) {
            if ($row['id'] === $user->id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rowFor(array $rows, User $user): array
    {
        $row = $this->findRow($rows, $user);
        $this->assertNotNull($row, 'Member is missing from the directory.');

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertNoForbiddenKeys(array $rows): void
    {
        foreach ($rows as $row) {
            foreach (self::FORBIDDEN as $key) {
                $this->assertArrayNotHasKey($key, $row);
            }
        }
    }
}
