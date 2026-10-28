<?php

namespace Tests\Database\Admin;

use App\Models\User;
use App\Models\UserGuest;
use Database\Factories\UserGuestFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * admin-api/registration-guests: list, edit and delete guests on registrations. Edits and deletes go
 * through the registration writer, so amount_owed follows the guests.
 */
class RegistrationGuestAdminTest extends AdminTestCase
{
    use RegistrationFixtures;

    private const BASE = '/admin-api/registration-guests';

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
    public function every_route_is_admin_only(string $who, int $status): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());
        $guest = UserGuest::query()->where('registration_id', $registration->id)->firstOrFail();
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);
        $expect = fn (int $ok) => $status === 200 ? $ok : $status;
        $body = $this->guestBody(['first' => 'Changed']);

        $as()->getJson(self::BASE)->assertStatus($expect(200));
        $as()->getJson(self::BASE.'/'.$guest->id)->assertStatus($expect(200));
        $as()->putJson(self::BASE.'/'.$guest->id, $body)->assertStatus($expect(200));
        $as()->deleteJson(self::BASE.'/'.$guest->id)->assertStatus($expect(204));

        if ($status !== 200) {
            $this->assertSame($guest->first, $guest->refresh()->first);
        }
    }

    #[Test]
    public function the_list_shows_the_event_and_member_and_filters_by_registration(): void
    {
        $show = $this->registrationShow(['name' => 'Summer Show']);
        $member = User::factory()->create(['first' => 'Grace', 'last' => 'Hopper']);
        $registration = $this->registrationWithCarAndGuest($show, [], $member);
        $this->registrationWithCarAndGuest($show);

        $this->actingAs($this->admin())->getJson(self::BASE.'?filter[registration_id]='.$registration->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.show_name', 'Summer Show')
            ->assertJsonPath('data.0.member_name', 'Grace Hopper')
            ->assertJsonPath('data.0.type', 'Adult');
    }

    #[Test]
    public function unknown_sort_is_a_422(): void
    {
        $this->actingAs($this->admin())->getJson(self::BASE.'?sort=first;drop table')->assertStatus(422);
    }

    #[Test]
    public function an_admin_edits_a_guest_and_the_amount_follows(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());
        $guest = UserGuest::query()->where('registration_id', $registration->id)->firstOrFail();

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$guest->id, $this->guestBody(['type_id' => 2]))
            ->assertOk()
            ->assertJsonPath('data.type', 'Child (Under 13)');

        $this->assertSame(2, $guest->refresh()->type_id->value);
        $this->assertSame('35.00', (string) $registration->refresh()->amount_owed);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidGuests(): array
    {
        return [
            'first too long (60)' => [['first' => str_repeat('a', 61)], 'first'],
            'last not latin1 letters' => [['last' => "Shark \u{1F988}"], 'last'],
            'type not a guest type' => [['type_id' => 3], 'type_id'],
            'email malformed' => [['email' => 'not-an-email'], 'email'],
            'email too long (128)' => [['email' => str_repeat('a', 120).'@x.example'], 'email'],
            'email is the registrant' => [['email' => 'registrant@example.test'], 'email'],
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[Test]
    #[DataProvider('invalidGuests')]
    public function an_invalid_guest_edit_is_a_422_and_changes_nothing(array $change, string $field): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow(), [], User::factory()->create(['email' => 'registrant@example.test']));
        $guest = UserGuest::query()->where('registration_id', $registration->id)->firstOrFail();
        $before = $guest->first;

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$guest->id, $this->guestBody($change))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
        $this->assertSame($before, $guest->refresh()->first);
    }

    #[Test]
    public function a_guest_edit_is_audited_without_the_name_or_email(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());
        $guest = UserGuest::query()->where('registration_id', $registration->id)->firstOrFail();

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$guest->id, $this->guestBody(['first' => 'Secretfirst', 'email' => 'secret@example.test']))
            ->assertOk();

        $entry = collect($this->auditEntries())->firstWhere('action', 'registration_guest.update');
        $this->assertSame('[redacted]', $entry['changes']['first']);
        $this->assertStringNotContainsString('Secretfirst', $this->auditText());
        $this->assertStringNotContainsString('secret@example.test', $this->auditText());
    }

    #[Test]
    public function deleting_a_guest_reprices_the_registration(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow(), ['amount_owed' => '65.00']);
        $extra = UserGuestFactory::new()->forRegistration($registration)->create();

        $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$extra->id)->assertNoContent();

        $this->assertNull(UserGuest::query()->find($extra->id));
        $this->assertSame('45.00', (string) $registration->refresh()->amount_owed);
        $this->assertNotNull(collect($this->auditEntries())->firstWhere('action', 'registration_guest.delete'));
    }

    #[Test]
    public function a_writer_rejection_is_a_422_with_its_message_and_changes_nothing(): void
    {
        $show = $this->registrationShow(['price_car' => 9000, 'price_adult' => 900, 'price_child' => 2000]);
        $registration = $this->registrationWithCarAndGuest($show);
        $guest = UserGuest::query()->where('registration_id', $registration->id)->firstOrFail();

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$guest->id, $this->guestBody(['type_id' => 2]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'The registration total is larger than we can record. Please contact us.')
            ->assertJsonStructure(['errors' => ['general.total']]);

        $this->assertSame(1, $guest->refresh()->type_id->value);
    }

    #[Test]
    public function a_guest_on_a_legacy_negative_discount_row_can_be_edited(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow(), ['discount' => '-30.00']);
        $guest = UserGuest::query()->where('registration_id', $registration->id)->firstOrFail();

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$guest->id, $this->guestBody(['type_id' => 2]))->assertOk();

        $this->assertSame('65.00', (string) $registration->refresh()->amount_owed);
    }

    #[Test]
    #[DataProvider('actors')]
    public function creating_a_guest_is_admin_only(string $who, int $status): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);

        $as()->postJson(self::BASE, $this->guestBody(['registration_id' => $registration->id]))->assertStatus($status === 200 ? 201 : $status);
        $this->assertSame($status === 200 ? 2 : 1, UserGuest::query()->where('registration_id', $registration->id)->count());
    }

    #[Test]
    public function an_admin_adds_a_guest_and_the_amount_follows(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());

        $this->actingAs($this->admin())->postJson(self::BASE, $this->guestBody(['registration_id' => $registration->id, 'type_id' => 2, 'first' => 'Kid']))
            ->assertCreated()
            ->assertJsonPath('data.first', 'Kid')
            ->assertJsonPath('data.registration_id', $registration->id);

        $this->assertSame('55.00', (string) $registration->refresh()->amount_owed);
        $entry = collect($this->auditEntries())->firstWhere('action', 'registration_guest.create');
        $this->assertSame($registration->id, $entry['extra']['registration_id']);
        $this->assertStringNotContainsString('Kid', $this->auditText());
    }

    #[Test]
    public function creating_a_guest_needs_an_existing_registration_and_valid_fields(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(self::BASE, $this->guestBody())->assertStatus(422)->assertJsonValidationErrors('registration_id');
        $this->actingAs($admin)->postJson(self::BASE, $this->guestBody(['registration_id' => 999999]))->assertStatus(422)->assertJsonValidationErrors('registration_id');
        $this->actingAs($admin)->postJson(self::BASE, $this->guestBody(['registration_id' => $registration->id, 'first' => '']))->assertStatus(422)->assertJsonValidationErrors('first');
        $this->assertSame(1, UserGuest::query()->where('registration_id', $registration->id)->count());
    }

    #[Test]
    public function a_guest_on_a_disabled_members_registration_is_a_422_not_a_500(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());
        $guest = UserGuest::query()->where('registration_id', $registration->id)->firstOrFail();
        User::query()->whereKey($registration->user_id)->firstOrFail()->delete();
        $admin = $this->admin();

        $this->actingAs($admin)->putJson(self::BASE.'/'.$guest->id, $this->guestBody())->assertStatus(422)->assertJsonValidationErrors('registration_id');
        $this->actingAs($admin)->postJson(self::BASE, $this->guestBody(['registration_id' => $registration->id]))->assertStatus(422)->assertJsonValidationErrors('registration_id');
        $this->actingAs($admin)->deleteJson(self::BASE.'/'.$guest->id)->assertStatus(422)->assertJsonStructure(['errors' => ['general.user']]);
    }

    #[Test]
    public function a_guest_moves_to_another_registration_of_the_event_and_both_are_repriced(): void
    {
        $show = $this->registrationShow();
        $from = $this->registrationWithCarAndGuest($show, ['amount_owed' => '65.00']);
        $moving = UserGuestFactory::new()->forRegistration($from)->create();
        $to = $this->registrationWithCarAndGuest($show);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$moving->id, $this->guestBody(['registration_id' => $to->id]))
            ->assertOk()
            ->assertJsonPath('data.registration_id', $to->id);

        $moving->refresh();
        $this->assertSame($to->id, (int) $moving->registration_id);
        $this->assertSame($to->user_id, (int) $moving->user_id);
        $this->assertSame('45.00', (string) $from->refresh()->amount_owed);
        $this->assertSame('65.00', (string) $to->refresh()->amount_owed);
        $entry = collect($this->auditEntries())->firstWhere('action', 'registration_guest.update');
        $this->assertSame(['old' => $from->id, 'new' => $to->id], $entry['changes']['registration_id']);
    }

    #[Test]
    public function a_guest_cannot_move_to_another_event(): void
    {
        $from = $this->registrationWithCarAndGuest($this->registrationShow());
        $guest = UserGuest::query()->where('registration_id', $from->id)->firstOrFail();
        $elsewhere = $this->registrationWithCarAndGuest($this->registrationShow());

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$guest->id, $this->guestBody(['registration_id' => $elsewhere->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('registration_id');
        $this->assertSame($from->id, (int) $guest->refresh()->registration_id);
    }

    #[Test]
    public function a_missing_guest_is_a_json_404(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(self::BASE.'/999999')->assertNotFound()->assertJsonPath('message', 'Registration guest not found.');
        $this->actingAs($admin)->deleteJson(self::BASE.'/999999')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function guestBody(array $overrides = []): array
    {
        return array_merge(['first' => 'Ann', 'last' => 'Guest', 'email' => null, 'type_id' => 1], $overrides);
    }
}
