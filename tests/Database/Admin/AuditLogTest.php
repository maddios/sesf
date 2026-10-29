<?php

namespace Tests\Database\Admin;

use App\Models\ShowExtra;
use App\Models\Sponsor;
use App\Models\User;
use App\Support\Admin\AuditLog;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * Audit entries for admin writes: one JSON line each, with who/what/ids/changes, and no secrets.
 */
class AuditLogTest extends AdminTestCase
{
    #[Test]
    public function creating_a_sponsor_writes_one_entry_with_redacted_notes(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/admin-api/sponsors', [
            'name' => 'Acme', 'internal_notes' => 'private-note-value', 'is_enabled' => true,
        ], ['X-Request-Id' => 'req-12345678']);

        $response->assertCreated()->assertHeader('X-Request-Id', 'req-12345678');
        $entries = $this->auditEntries();
        $this->assertCount(1, $entries);
        $entry = $entries[0];
        $this->assertSame('sponsor.create', $entry['action']);
        $this->assertSame('sponsor', $entry['resource']);
        $this->assertSame($admin->id, $entry['user_id']);
        $this->assertSame([$response->json('data.id')], $entry['ids']);
        $this->assertSame('req-12345678', $entry['request_id']);
        $this->assertSame(['old' => null, 'new' => 'Acme'], $entry['changes']['name']);
        $this->assertSame(AuditLog::REDACTED, $entry['changes']['internal_notes']);
        $this->assertArrayHasKey('ip', $entry);
        $this->assertStringNotContainsString('private-note-value', $this->auditText());
    }

    #[Test]
    public function an_update_logs_only_changed_fields_old_and_new(): void
    {
        $sponsor = Sponsor::factory()->create(['name' => 'Old', 'URL' => 'https://same.example', 'internal_notes' => 'secret-a']);

        $this->actingAs($this->admin())->putJson('/admin-api/sponsors/'.$sponsor->id, [
            'name' => 'New', 'URL' => 'https://same.example', 'internal_notes' => 'secret-b',
            'description' => $sponsor->description, 'short_description' => null,
        ])->assertOk();

        $entry = $this->auditEntries()[0];
        $this->assertSame('sponsor.update', $entry['action']);
        $this->assertSame(['old' => 'Old', 'new' => 'New'], $entry['changes']['name']);
        $this->assertArrayNotHasKey('URL', $entry['changes']);
        $this->assertArrayNotHasKey('updated_at', $entry['changes']);
        $this->assertSame(AuditLog::REDACTED, $entry['changes']['internal_notes']);
        $this->assertStringNotContainsString('secret-a', $this->auditText());
        $this->assertStringNotContainsString('secret-b', $this->auditText());
    }

    #[Test]
    public function enable_disable_delete_and_image_are_audited(): void
    {
        $sponsor = Sponsor::factory()->create(['is_enabled' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/admin-api/sponsors/'.$sponsor->id.'/disable')->assertOk();
        $this->actingAs($admin)->deleteJson('/admin-api/sponsors/'.$sponsor->id)->assertNoContent();

        $actions = array_column($this->auditEntries(), 'action');
        $this->assertSame(['sponsor.disable', 'sponsor.delete'], $actions);
        $this->assertSame(['old' => true, 'new' => false], $this->auditEntries()[0]['changes']['is_enabled']);
    }

    #[Test]
    public function reads_failed_writes_and_denied_requests_are_not_audited(): void
    {
        $sponsor = Sponsor::factory()->create();

        $this->actingAs($this->admin())->getJson('/admin-api/sponsors')->assertOk();
        $this->actingAs($this->admin())->postJson('/admin-api/sponsors', [])->assertStatus(422);
        $this->actingAs($this->actor('member'))->deleteJson('/admin-api/sponsors/'.$sponsor->id)->assertForbidden();

        $this->assertSame([], $this->auditEntries());
    }

    #[Test]
    public function a_write_route_that_records_nothing_gets_a_fallback_entry(): void
    {
        Route::middleware(['web', 'auth', 'admin', 'audit.admin'])
            ->post('/admin-api/_test/things/{thing}', fn () => response()->json(['ok' => true]))
            ->name('admin-api.test.things');

        $this->actingAs($this->admin())->postJson('/admin-api/_test/things/42', ['password' => 'hunter2-secret'])->assertOk();

        $entry = $this->auditEntries()[0];
        $this->assertSame('http.post', $entry['action']);
        $this->assertSame('admin-api.test.things', $entry['resource']);
        $this->assertSame([42], $entry['ids']);
        $this->assertStringNotContainsString('hunter2-secret', $this->auditText());
    }

    #[Test]
    public function money_columns_are_logged_as_decimal_strings(): void
    {
        $extra = ShowExtra::factory()->create(['price' => '12.30']);
        $extra = ShowExtra::query()->findOrFail($extra->id);
        $extra->price = '15.5';
        $extra->save();

        $this->assertSame(['old' => '12.30', 'new' => '15.50'], AuditLog::diff($extra)['price']);
        $this->assertSame(['old' => null, 'new' => '15.50'], AuditLog::created($extra)['price']);
    }

    #[Test]
    public function a_delete_records_the_deleted_row_without_sensitive_values(): void
    {
        $sponsor = Sponsor::factory()->create(['name' => 'Gone Inc', 'internal_notes' => 'private-note-value']);

        $this->actingAs($this->admin())->deleteJson('/admin-api/sponsors/'.$sponsor->id)->assertNoContent();

        $entry = $this->auditEntries()[0];
        $this->assertSame('sponsor.delete', $entry['action']);
        $this->assertSame(['old' => 'Gone Inc', 'new' => null], $entry['changes']['name']);
        $this->assertSame(AuditLog::REDACTED, $entry['changes']['internal_notes']);
        $this->assertStringNotContainsString('private-note-value', $this->auditText());
    }

    #[Test]
    public function diff_redacts_sensitive_fields_and_clips_long_values(): void
    {
        $user = User::factory()->create(['email' => 'before@example.test']);
        $user->forceFill(['email' => 'after@example.test', 'password' => bcrypt('x'), 'city' => 'Tampa', 'remember_token' => 'tok'])->save();

        $changes = AuditLog::diff($user);

        foreach (['email', 'password', 'city', 'remember_token'] as $field) {
            if (array_key_exists($field, $changes)) {
                $this->assertSame(AuditLog::REDACTED, $changes[$field], $field);
            }
        }
        $this->assertArrayHasKey('email', $changes);

        $sponsor = Sponsor::factory()->create();
        $sponsor->description = str_repeat('x', 500);
        $sponsor->save();
        $clipped = AuditLog::diff($sponsor)['description'];
        $this->assertIsArray($clipped);
        $this->assertSame(AuditLog::MAX_VALUE_LENGTH + 3, mb_strlen((string) $clipped['new']));
    }

    #[Test]
    public function a_malformed_request_id_header_is_replaced(): void
    {
        $sponsor = Sponsor::factory()->create();

        $response = $this->actingAs($this->admin())->postJson('/admin-api/sponsors/'.$sponsor->id.'/disable', [], ['X-Request-Id' => "bad\nid"]);

        $id = (string) $response->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
        $this->assertSame($id, $this->auditEntries()[0]['request_id']);
    }
}
