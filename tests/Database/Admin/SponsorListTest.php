<?php

namespace Tests\Database\Admin;

use App\Models\ShowSponsor;
use App\Models\Sponsor;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET admin-api/sponsors through AdminQuery: paging, sort, search, filters and injection attempts.
 */
class SponsorListTest extends AdminTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->admin();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return TestResponse<Response>
     */
    private function list(array $query = []): TestResponse
    {
        return $this->actingAs($this->user)->getJson('/admin-api/sponsors?'.http_build_query($query));
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    private function names(TestResponse $response): array
    {
        return array_column($response->json('data'), 'name');
    }

    #[Test]
    public function the_list_is_paginated_with_meta(): void
    {
        Sponsor::factory()->count(7)->create();

        $response = $this->list(['per_page' => 3, 'page' => 2]);

        $response->assertOk()
            ->assertJsonPath('meta', ['page' => 2, 'per_page' => 3, 'total' => 7, 'last_page' => 3])
            ->assertJsonCount(3, 'data');
        $this->list(['per_page' => 3, 'page' => 3])->assertJsonCount(1, 'data');
        $this->list(['per_page' => 3, 'page' => 9])->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_default_sort_is_name_ascending_and_sort_can_be_changed(): void
    {
        foreach (['Charlie', 'alpha', 'Bravo'] as $name) {
            Sponsor::factory()->create(['name' => $name]);
        }

        $this->assertSame(['alpha', 'Bravo', 'Charlie'], $this->names($this->list()));
        $this->assertSame(['Charlie', 'Bravo', 'alpha'], $this->names($this->list(['sort' => 'name', 'direction' => 'desc'])));
        $this->assertSame(['Charlie', 'alpha', 'Bravo'], $this->names($this->list(['sort' => 'id'])));
    }

    #[Test]
    public function search_matches_name_url_and_short_description_literally(): void
    {
        Sponsor::factory()->create(['name' => 'Acme', 'URL' => 'https://acme.example']);
        Sponsor::factory()->create(['name' => 'Bolt', 'URL' => null, 'short_description' => 'acme partner']);
        Sponsor::factory()->create(['name' => '100% Cotton', 'URL' => null]);
        Sponsor::factory()->create(['name' => 'Other', 'URL' => null]);

        $this->assertSame(['Acme', 'Bolt'], $this->names($this->list(['search' => 'acme'])));
        // Wildcards are escaped: "%" matches only a literal percent sign, "_" only an underscore.
        $this->assertSame(['100% Cotton'], $this->names($this->list(['search' => '%'])));
        $this->assertSame([], $this->names($this->list(['search' => '_'])));
    }

    #[Test]
    public function filters_eq_like_in_null_and_between_work(): void
    {
        $a = Sponsor::factory()->create(['name' => 'Enabled A', 'is_enabled' => true, 'URL' => null, 'created_at' => '2020-01-05 00:00:00']);
        $b = Sponsor::factory()->create(['name' => 'Disabled B', 'is_enabled' => false, 'created_at' => '2021-06-01 00:00:00']);
        $c = Sponsor::factory()->create(['name' => 'Enabled C', 'is_enabled' => true, 'created_at' => '2022-01-01 00:00:00']);

        $this->assertSame(['Enabled A', 'Enabled C'], $this->names($this->list(['filter' => ['is_enabled' => '1']])));
        $this->assertSame(['Disabled B'], $this->names($this->list(['filter' => ['is_enabled' => ['eq' => '0']]])));
        $this->assertSame(['Disabled B'], $this->names($this->list(['filter' => ['name' => ['like' => 'abled B']]])));
        $this->assertSame(['Disabled B', 'Enabled A'], $this->names($this->list(['filter' => ['id' => ['in' => $a->id.','.$b->id]]])));
        $this->assertSame(['Enabled A'], $this->names($this->list(['filter' => ['URL' => ['null' => '1']]])));
        $this->assertSame(['Disabled B', 'Enabled C'], $this->names($this->list(['filter' => ['URL' => ['null' => '0']]])));
        $this->assertSame(['Disabled B'], $this->names($this->list(['filter' => ['created_at' => ['between' => ['2021-01-01', '2021-12-31']]]])));
        $this->assertSame(['Enabled C'], $this->names($this->list(['filter' => ['id' => ['in' => [$c->id]]]])));
    }

    #[Test]
    public function the_show_filter_lists_sponsors_of_one_show(): void
    {
        $link = ShowSponsor::factory()->create();
        Sponsor::factory()->create(['name' => 'Not linked']);

        $response = $this->list(['filter' => ['show_id' => (string) $link->show_id]]);

        $this->assertSame([$link->sponsor_id], array_column($response->json('data'), 'id'));
        $this->list(['filter' => ['show_id' => 'abc']])->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function rejected(): array
    {
        return [
            'sort injection' => [['sort' => 'name;drop table sponsor'], 'sort'],
            'sort subquery' => [['sort' => '(select 1)'], 'sort'],
            'sort not whitelisted' => [['sort' => 'internal_notes'], 'sort'],
            'sort array' => [['sort' => ['name']], 'sort'],
            'direction injection' => [['direction' => 'asc, (select sleep(5))'], 'direction'],
            'filter column not whitelisted' => [['filter' => ['internal_notes' => 'x']], 'filter.internal_notes'],
            'filter column injection' => [['filter' => ['name` = 1 or 1=1 -- ' => 'x']], 'filter.name` = 1 or 1=1 -- '],
            'filter operator not allowed' => [['filter' => ['is_enabled' => ['like' => '1']]], 'filter.is_enabled'],
            'filter operator unknown' => [['filter' => ['name' => ['regexp' => '.*']]], 'filter.name'],
            'filter raw operator' => [['filter' => ['name' => ['>' => 'a']]], 'filter.name'],
            'filter null bad value' => [['filter' => ['URL' => ['null' => 'yes']]], 'filter.URL'],
            'filter between one value' => [['filter' => ['created_at' => ['between' => '2020-01-01']]], 'filter.created_at'],
            'filter in too many' => [['filter' => ['id' => ['in' => implode(',', range(1, 101))]]], 'filter.id'],
            'filter value array for eq' => [['filter' => ['name' => ['eq' => ['a', 'b']]]], 'filter.name'],
            'filter not an array' => [['filter' => 'name'], 'filter'],
            'per_page above 100' => [['per_page' => 101], 'per_page'],
            'per_page zero' => [['per_page' => 0], 'per_page'],
            'per_page not a number' => [['per_page' => '10; drop'], 'per_page'],
            'page negative' => [['page' => -1], 'page'],
            'page not a number' => [['page' => '1 or 1=1'], 'page'],
            'search too long' => [['search' => str_repeat('a', 101)], 'search'],
            'search array' => [['search' => ['a']], 'search'],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('rejected')]
    public function unsafe_or_unknown_parameters_are_a_422(array $query, string $field): void
    {
        Sponsor::factory()->create();

        $this->list($query)->assertStatus(422)->assertJsonValidationErrors([$field]);
        // The table is still there.
        $this->assertSame(1, Sponsor::query()->count());
    }

    #[Test]
    public function a_search_with_sql_is_only_a_literal_search(): void
    {
        Sponsor::factory()->create(['name' => 'Acme']);

        $this->list(['search' => "' OR '1'='1"])->assertOk()->assertJsonCount(0, 'data');
        $this->list(['filter' => ['name' => "Acme' OR '1'='1"]])->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, Sponsor::query()->count());
    }
}
