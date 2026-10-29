<?php

namespace Tests\Database\Admin;

use App\Enums\ExtraType;
use App\Models\CarShow;
use App\Models\ShowExtra;
use App\Models\ShowExtraRegistration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * admin-api/extras: CRUD, validation and widths, money in cents, the public auth_key, deletes that orphan
 * registrations (409), and audit entries.
 */
class ExtraAdminTest extends AdminTestCase
{
    private const BASE = '/admin-api/extras';

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
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Pin',
            'description' => 'Enamel pin',
            'auth_key' => 'pin-2026',
            'price' => '5.00',
            'type_id' => ExtraType::Cars->value,
            'min_value' => 1,
            'max_value' => 3,
            'is_enabled' => true,
        ], $overrides);
    }

    #[Test]
    #[DataProvider('actors')]
    public function every_route_is_admin_only(string $who, int $status): void
    {
        $extra = ShowExtra::factory()->create();
        $show = CarShow::factory()->create();
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);
        $expect = fn (int $ok) => $status === 200 ? $ok : $status;

        $as()->getJson(self::BASE)->assertStatus($expect(200));
        $as()->getJson(self::BASE.'/'.$extra->id)->assertStatus($expect(200));
        $as()->postJson(self::BASE, $this->payload(['show_id' => $show->id]))->assertStatus($expect(201));
        $as()->putJson(self::BASE.'/'.$extra->id, $this->payload(['auth_key' => 'other-key']))->assertStatus($expect(200));
        $as()->deleteJson(self::BASE.'/'.$extra->id)->assertStatus($expect(204));

        if ($status !== 200) {
            $this->assertTrue(ShowExtra::query()->whereKey($extra->id)->exists());
        }
    }

    #[Test]
    public function an_admin_creates_an_extra_with_its_price_in_cents_and_its_landing_key(): void
    {
        $show = CarShow::factory()->create();

        $response = $this->actingAs($this->admin())->postJson(self::BASE, $this->payload(['show_id' => $show->id, 'price' => '12.345']));

        $response->assertCreated()
            ->assertJsonPath('data.price', '12.35')
            ->assertJsonPath('data.auth_key', 'pin-2026')
            ->assertJsonPath('data.type', 'Cars')
            ->assertJsonPath('data.registrations_count', 0)
            ->assertJsonPath('data.in_use', false)
            ->assertJsonPath('data.show_id', $show->id);
        $this->assertSame('12.35', ShowExtra::query()->findOrFail($response->json('data.id'))->price);
    }

    #[Test]
    public function the_price_is_exact_for_decimal_input(): void
    {
        $extra = ShowExtra::factory()->create(['price' => 0]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$extra->id, $this->payload(['price' => '0.30']))
            ->assertOk()
            ->assertJsonPath('data.price', '0.30');
    }

    #[Test]
    public function an_update_keeps_the_event_and_is_enabled_when_not_sent(): void
    {
        $show = CarShow::factory()->create();
        $extra = ShowExtra::factory()->create(['show_id' => $show->id, 'is_enabled' => true]);
        $body = $this->payload();
        unset($body['is_enabled']);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$extra->id, $body + ['show_id' => 999999])->assertOk();

        $extra->refresh();
        $this->assertTrue((bool) $extra->is_enabled);
        $this->assertSame($show->id, (int) $extra->show_id);
        $this->assertSame('Pin', $extra->name);
    }

    #[Test]
    public function the_auth_key_is_unique_and_optional(): void
    {
        ShowExtra::factory()->create(['auth_key' => 'taken-key']);
        $admin = $this->admin();
        $show = CarShow::factory()->create();

        $this->actingAs($admin)->postJson(self::BASE, $this->payload(['show_id' => $show->id, 'auth_key' => 'taken-key']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['auth_key']);

        $this->actingAs($admin)->postJson(self::BASE, $this->payload(['show_id' => $show->id, 'auth_key' => null]))->assertCreated()->assertJsonPath('data.auth_key', null);
        $this->actingAs($admin)->postJson(self::BASE, $this->payload(['show_id' => $show->id, 'auth_key' => null, 'name' => 'Other']))->assertCreated();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'name missing' => [['name' => null], 'name'],
            'name too long (64)' => [['name' => str_repeat('a', 65)], 'name'],
            'name not latin1' => [['name' => "Pin \u{1F3C1}"], 'name'],
            'description too long (text)' => [['description' => str_repeat('a', 65536)], 'description'],
            'auth_key too long (64)' => [['auth_key' => str_repeat('a', 65)], 'auth_key'],
            'auth_key not URL-safe' => [['auth_key' => 'bad key!'], 'auth_key'],
            'price missing' => [['price' => null], 'price'],
            'price not a number' => [['price' => 'five'], 'price'],
            'price negative' => [['price' => '-1.00'], 'price'],
            'price above double(10,2)' => [['price' => '100000000.00'], 'price'],
            'type unknown' => [['type_id' => 99], 'type_id'],
            'min_value negative' => [['min_value' => -1], 'min_value'],
            'max_value below min_value' => [['min_value' => 5, 'max_value' => 2], 'max_value'],
            'is_enabled not boolean' => [['is_enabled' => 'maybe'], 'is_enabled'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('invalidPayloads')]
    public function invalid_input_is_a_standard_422_on_the_field(array $overrides, string $field): void
    {
        $admin = $this->admin();
        $show = CarShow::factory()->create();
        $this->actingAs($admin)->postJson(self::BASE, $this->payload(['show_id' => $show->id] + $overrides))
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => [$field]]);

        $extra = ShowExtra::factory()->create(['name' => 'Keep']);
        $this->actingAs($admin)->putJson(self::BASE.'/'.$extra->id, $this->payload($overrides))->assertStatus(422)->assertJsonValidationErrors($field);
        $this->assertSame('Keep', $extra->refresh()->name);
    }

    #[Test]
    public function the_event_is_required_on_create(): void
    {
        $this->actingAs($this->admin())->postJson(self::BASE, $this->payload())->assertStatus(422)->assertJsonValidationErrors(['show_id']);
    }

    #[Test]
    public function values_at_the_column_widths_are_accepted(): void
    {
        $show = CarShow::factory()->create();

        $this->actingAs($this->admin())->postJson(self::BASE, $this->payload([
            'show_id' => $show->id,
            'name' => str_repeat('n', 64),
            'description' => str_repeat('d', 65535),
            'auth_key' => str_repeat('k', 64),
            'price' => '99999999.99',
            'max_value' => 4294967295,
        ]))->assertCreated();
    }

    #[Test]
    public function the_list_filters_by_event_and_searches_name_and_key(): void
    {
        $show = CarShow::factory()->create();
        $match = ShowExtra::factory()->create(['show_id' => $show->id, 'name' => 'Dinner', 'auth_key' => 'dinner36']);
        ShowExtra::factory()->create(['show_id' => $show->id, 'name' => 'Pin', 'auth_key' => 'pin36']);
        ShowExtra::factory()->create(['name' => 'Dinner']);

        $this->actingAs($this->admin())->getJson(self::BASE.'?filter[show_id]='.$show->id.'&search=dinner')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileQueries(): array
    {
        return [
            'sort injection' => ['sort=name;drop table show_extra'],
            'unknown operator' => ['filter[price][like]=5'],
            'unknown filter column' => ['filter[auth_key]=x'],
        ];
    }

    #[Test]
    #[DataProvider('hostileQueries')]
    public function hostile_list_queries_are_422(string $query): void
    {
        $this->actingAs($this->admin())->getJson(self::BASE.'?'.$query)->assertStatus(422);
    }

    #[Test]
    public function a_missing_extra_is_a_json_404(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->getJson(self::BASE.'/999999')->assertNotFound()->assertJsonPath('message', 'Extra not found.');
        $this->actingAs($admin)->putJson(self::BASE.'/999999', $this->payload())->assertNotFound();
        $this->actingAs($admin)->deleteJson(self::BASE.'/999999')->assertNotFound();
    }

    #[Test]
    public function an_extra_with_registrations_is_a_409_and_is_kept(): void
    {
        $extra = ShowExtra::factory()->create();
        ShowExtraRegistration::factory()->create(['show_extra_id' => $extra->id]);

        $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$extra->id)
            ->assertStatus(409)
            ->assertJsonPath('references.registrations', 1)
            ->assertJsonPath('message', 'This extra has 1 registration(s) and cannot be deleted. Disable it instead.');

        $this->assertTrue(ShowExtra::query()->whereKey($extra->id)->exists());
        $this->assertNotContains('extra.delete', array_column($this->auditEntries(), 'action'));
    }

    #[Test]
    public function an_unused_extra_is_deleted_and_audited(): void
    {
        $extra = ShowExtra::factory()->create();

        $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$extra->id)->assertNoContent();

        $this->assertFalse(ShowExtra::query()->whereKey($extra->id)->exists());
        $this->assertContains('extra.delete', array_column($this->auditEntries(), 'action'));
    }

    #[Test]
    public function the_audit_logs_the_price_change_but_not_the_landing_key(): void
    {
        $extra = ShowExtra::factory()->create(['price' => 5.00, 'auth_key' => 'secret-landing']);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$extra->id, $this->payload(['price' => '7.50', 'auth_key' => 'new-landing']))->assertOk();

        $entries = collect($this->auditEntries())->keyBy('action');
        $this->assertEqualsWithDelta(5.0, $entries['extra.update']['changes']['price']['old'], 0.001);
        $this->assertEqualsWithDelta(7.5, $entries['extra.update']['changes']['price']['new'], 0.001);
        $this->assertStringNotContainsString('new-landing', $this->auditText());
        $this->assertStringNotContainsString('secret-landing', $this->auditText());
    }
}
