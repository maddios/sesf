<?php

namespace Tests\Database\Admin;

use App\Models\CarShow;
use App\Models\ShowSponsor;
use App\Models\Sponsor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * admin-api/sponsors CRUD, enable/disable, validation, widths and safe deletes.
 */
class SponsorAdminTest extends AdminTestCase
{
    private const BASE = '/admin-api/sponsors';

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

    #[Test]
    #[DataProvider('actors')]
    public function the_list_is_admin_only(string $who, int $status): void
    {
        Sponsor::factory()->create();
        $user = $this->actor($who);
        $request = $user === null ? $this : $this->actingAs($user);

        $request->getJson(self::BASE)->assertStatus($status);
    }

    #[Test]
    #[DataProvider('actors')]
    public function every_write_is_admin_only(string $who, int $status): void
    {
        $sponsor = Sponsor::factory()->create();
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);

        $expect = fn (int $ok) => $status === 200 ? $ok : $status;

        $as()->postJson(self::BASE, ['name' => 'Acme'])->assertStatus($expect(201));
        $as()->getJson(self::BASE.'/'.$sponsor->id)->assertStatus($expect(200));
        $as()->putJson(self::BASE.'/'.$sponsor->id, ['name' => 'Renamed'])->assertStatus($expect(200));
        $as()->postJson(self::BASE.'/'.$sponsor->id.'/disable')->assertStatus($expect(200));
        $as()->postJson(self::BASE.'/'.$sponsor->id.'/enable')->assertStatus($expect(200));
        $as()->deleteJson(self::BASE.'/'.$sponsor->id)->assertStatus($expect(204));

        if ($status !== 200) {
            $this->assertSame(1, Sponsor::query()->count());
            $this->assertSame($sponsor->name, $sponsor->refresh()->name);
        }
    }

    #[Test]
    public function an_html_guest_is_redirected_to_the_spa_login(): void
    {
        $this->get(self::BASE)->assertRedirect('/#/login');
    }

    #[Test]
    public function an_admin_creates_a_sponsor_with_every_field(): void
    {
        $response = $this->actingAs($this->admin())->postJson(self::BASE, [
            'name' => '  Acme Tools ',
            'URL' => 'https://acme.example',
            'description' => 'Long text',
            'short_description' => 'Short',
            'internal_notes' => 'Call Bob',
            'is_enabled' => true,
            'sort_order' => 4,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Acme Tools')
            ->assertJsonPath('data.internal_notes', 'Call Bob')
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.in_use', false);

        $sponsor = Sponsor::query()->findOrFail($response->json('data.id'));
        $this->assertSame('https://acme.example', $sponsor->URL);
        $this->assertTrue($sponsor->is_enabled);
        $this->assertSame(4, $sponsor->sort_order);
    }

    #[Test]
    public function a_new_sponsor_is_disabled_unless_asked_and_blank_text_is_stored_as_null(): void
    {
        $response = $this->actingAs($this->admin())->postJson(self::BASE, ['name' => 'Acme', 'URL' => '', 'description' => '   ']);

        $response->assertCreated()->assertJsonPath('data.is_enabled', false);
        $sponsor = Sponsor::query()->findOrFail($response->json('data.id'));
        $this->assertNull($sponsor->URL);
        $this->assertNull($sponsor->description);
    }

    #[Test]
    public function an_update_changes_fields_and_keeps_is_enabled_when_not_sent(): void
    {
        $sponsor = Sponsor::factory()->create(['name' => 'Old', 'is_enabled' => true]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$sponsor->id, [
            'name' => 'New', 'URL' => 'http://new.example', 'internal_notes' => null,
        ])->assertOk()->assertJsonPath('data.name', 'New');

        $sponsor->refresh();
        $this->assertSame('New', $sponsor->name);
        $this->assertNull($sponsor->internal_notes);
        $this->assertTrue($sponsor->is_enabled);
    }

    #[Test]
    public function enable_and_disable_flip_is_enabled(): void
    {
        $sponsor = Sponsor::factory()->create(['is_enabled' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(self::BASE.'/'.$sponsor->id.'/disable')->assertOk()->assertJsonPath('data.is_enabled', false);
        $this->assertFalse($sponsor->refresh()->is_enabled);
        $this->actingAs($admin)->postJson(self::BASE.'/'.$sponsor->id.'/enable')->assertOk()->assertJsonPath('data.is_enabled', true);
        $this->assertTrue($sponsor->refresh()->is_enabled);
    }

    #[Test]
    public function the_admin_view_includes_internal_notes_and_usage(): void
    {
        $sponsor = Sponsor::factory()->create(['internal_notes' => 'staff only']);
        ShowSponsor::factory()->create(['sponsor_id' => $sponsor->id]);

        $this->actingAs($this->admin())->getJson(self::BASE.'/'.$sponsor->id)
            ->assertOk()
            ->assertJsonPath('data.internal_notes', 'staff only')
            ->assertJsonPath('data.shows_count', 1)
            ->assertJsonPath('data.in_use', true);
    }

    #[Test]
    public function a_missing_sponsor_is_a_json_404(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->getJson(self::BASE.'/999999')->assertNotFound()->assertJsonPath('message', 'Sponsor not found.');
        $this->actingAs($admin)->putJson(self::BASE.'/999999', ['name' => 'x'])->assertNotFound();
        $this->actingAs($admin)->deleteJson(self::BASE.'/999999')->assertNotFound();
        $this->actingAs($admin)->getJson(self::BASE.'/abc')->assertNotFound();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'name missing' => [[], 'name'],
            'name too long (255)' => [['name' => str_repeat('a', 256)], 'name'],
            'name not latin1' => [['name' => "Shark \u{1F988}"], 'name'],
            'name not a string' => [['name' => ['x']], 'name'],
            'URL too long (255)' => [['name' => 'a', 'URL' => 'https://e.example/'.str_repeat('a', 240)], 'URL'],
            'URL not http(s)' => [['name' => 'a', 'URL' => 'javascript:alert(1)'], 'URL'],
            'URL not a URL' => [['name' => 'a', 'URL' => 'not a url'], 'URL'],
            'short_description too long (512)' => [['name' => 'a', 'short_description' => str_repeat('a', 513)], 'short_description'],
            'description too long (text)' => [['name' => 'a', 'description' => str_repeat('a', 65536)], 'description'],
            'internal_notes too long (text)' => [['name' => 'a', 'internal_notes' => str_repeat('a', 65536)], 'internal_notes'],
            'is_enabled not boolean' => [['name' => 'a', 'is_enabled' => 'maybe'], 'is_enabled'],
            'sort_order above smallint' => [['name' => 'a', 'sort_order' => 65536], 'sort_order'],
            'sort_order negative' => [['name' => 'a', 'sort_order' => -1], 'sort_order'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[DataProvider('invalidPayloads')]
    public function invalid_input_is_a_standard_422_on_the_field(array $payload, string $field): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson(self::BASE, $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => [$field]]);

        $sponsor = Sponsor::factory()->create(['name' => 'Keep']);
        $this->actingAs($admin)->putJson(self::BASE.'/'.$sponsor->id, $payload)->assertStatus(422)->assertJsonValidationErrors($field);
        $this->assertSame('Keep', $sponsor->refresh()->name);
    }

    #[Test]
    public function values_at_the_column_widths_are_accepted(): void
    {
        $this->actingAs($this->admin())->postJson(self::BASE, [
            'name' => str_repeat('n', 255),
            'URL' => 'https://e.example/'.str_repeat('a', 255 - 18),
            'short_description' => str_repeat('s', 512),
            'description' => str_repeat('d', 65535),
            'sort_order' => 65535,
        ])->assertCreated();
    }

    #[Test]
    public function an_unused_sponsor_is_deleted(): void
    {
        $sponsor = Sponsor::factory()->create();

        $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$sponsor->id)->assertNoContent();

        $this->assertNull(Sponsor::query()->find($sponsor->id));
    }

    #[Test]
    public function a_sponsor_linked_to_a_show_cannot_be_deleted(): void
    {
        $sponsor = Sponsor::factory()->create();
        ShowSponsor::factory()->count(2)->create(['sponsor_id' => $sponsor->id]);

        $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$sponsor->id)
            ->assertStatus(409)
            ->assertJsonPath('references.shows', 2)
            ->assertJsonPath('references.primary', 0)
            ->assertJson(fn ($json) => $json->whereType('message', 'string')->etc());

        $this->assertNotNull(Sponsor::query()->find($sponsor->id));
    }

    #[Test]
    public function a_primary_sponsor_of_a_show_cannot_be_deleted(): void
    {
        $sponsor = Sponsor::factory()->create();
        CarShow::factory()->create(['primary_sponsor_id' => $sponsor->id]);

        $response = $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$sponsor->id);

        $response->assertStatus(409)->assertJsonPath('references.primary', 1);
        $this->assertStringContainsString('Disable it instead', (string) $response->json('message'));
        $this->assertNotNull(Sponsor::query()->find($sponsor->id));
    }

    #[Test]
    public function a_spoofed_method_or_extra_fields_cannot_change_guarded_columns(): void
    {
        $sponsor = Sponsor::factory()->create();

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$sponsor->id, [
            'name' => 'Ok', 'id' => 9999, 'created_at' => '2000-01-01 00:00:00',
        ])->assertOk();

        $this->assertNotNull(Sponsor::query()->find($sponsor->id));
        $this->assertNull(Sponsor::query()->find(9999));
    }
}
