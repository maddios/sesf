<?php

namespace Tests\Database\Api\Registration\Write;

use App\Models\CarShow;
use Database\Factories\CarBodyFactory;
use Database\Factories\ShirtTypeFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST event/registration: access, IDOR, closed shows and validation (always 400, never 500).
 */
class RegistrationWriteSecurityTest extends WriteTestCase
{
    #[Test]
    public function anonymous_writes_get_401(): void
    {
        $show = $this->show();

        $this->postJson(self::SAVE.'?show_id='.$show->id, $this->body($show, [1]))->assertStatus(401);
        $this->postJson(self::QUOTE.'?show_id='.$show->id, $this->body($show, [1]))->assertStatus(401);
        $this->postJson(self::EXTRA.'?extra_id=abc', ['num_extras' => 1])->assertStatus(401);
    }

    #[Test]
    public function a_post_without_a_csrf_token_gets_419(): void
    {
        $this->app->instance('env', 'local');
        $show = $this->show();
        $user = $this->member();

        $this->signIn($user)->withHeader('Accept', 'application/json')
            ->post(self::SAVE.'?show_id='.$show->id, $this->body($show, [$this->car($user)->id]))
            ->assertStatus(419);
        $this->assertNull($this->storedRegistration($show, $user));
    }

    #[Test]
    public function unknown_show_is_404_and_missing_show_id_is_422(): void
    {
        $user = $this->member();

        $this->signIn($user)->postJson(self::SAVE.'?show_id=999999', ['registration' => []])->assertStatus(404);
        $this->signIn($user)->postJson(self::SAVE, ['registration' => []])->assertStatus(422);
    }

    #[Test]
    public function another_users_car_is_rejected(): void
    {
        $show = $this->show();
        $user = $this->member();
        $victim = $this->member();
        $theirs = $this->car($victim);

        $this->save($user, $show, $this->body($show, [$this->car($user)->id, $theirs->id]))
            ->assertStatus(400)
            ->assertJsonPath('cars.status', 'error')
            ->assertJsonStructure(['cars' => ['response' => ['1.id']]]);

        $this->assertNull($this->storedRegistration($show, $user));
        $this->assertSame(0, DB::table('show_registration_car')->where('car_id', $theirs->id)->count());
    }

    #[Test]
    public function a_sold_car_is_rejected(): void
    {
        $show = $this->show();
        $user = $this->member();
        $sold = $this->car($user);
        $sold->forceFill(['user_id' => null])->save();

        $this->save($user, $show, $this->body($show, [$sold->id]))->assertStatus(400)->assertJsonPath('cars.status', 'error');
    }

    #[Test]
    public function a_car_whose_body_is_not_allowed_is_rejected(): void
    {
        $show = $this->show();
        $user = $this->member();
        $car = $this->car($user, CarBodyFactory::OTHER);

        $this->save($user, $show, $this->body($show, [$car->id]))->assertStatus(400)->assertJsonStructure(['cars' => ['response' => ['0.id']]]);
    }

    #[Test]
    public function a_judging_type_not_offered_for_the_body_is_rejected(): void
    {
        $show = $this->show();
        $this->allowJudging($show, CarBodyFactory::E24, 1);
        $user = $this->member();
        $car = $this->car($user);

        $this->save($user, $show, $this->body($show, [], ['cars' => [['id' => $car->id, 'judging_type' => 3]]]))
            ->assertStatus(400)
            ->assertJsonStructure(['cars' => ['response' => ['0.judging_type']]]);
    }

    #[Test]
    public function at_least_one_car_is_required(): void
    {
        $show = $this->show();
        $user = $this->member();

        $this->save($user, $show, $this->body($show, []))
            ->assertStatus(400)
            ->assertJsonStructure(['cars' => ['response' => ['need_a_car']]]);
    }

    #[Test]
    public function another_registrations_guest_and_shirt_ids_are_rejected(): void
    {
        $show = $this->show();
        $victim = $this->member();
        $victimCar = $this->car($victim);
        $this->save($victim, $show, $this->body($show, [$victimCar->id], [
            'guests' => [['first' => 'Victim', 'last' => 'Guest', 'type' => 1]],
            'shirts' => [['type' => ShirtTypeFactory::MENS_S_TSHIRT]],
        ]))->assertOk();
        $victimRegistration = $this->storedRegistration($show, $victim);
        $this->assertNotNull($victimRegistration);
        $guestId = (int) $victimRegistration->guests()->value('id');
        $shirtId = (int) $victimRegistration->shirts()->value('id');

        $user = $this->member();
        $car = $this->car($user);
        $this->save($user, $show, $this->body($show, [$car->id]))->assertOk();

        $this->save($user, $show, $this->body($show, [$car->id], [
            'guests' => [['id' => $guestId, 'first' => 'Hijack', 'last' => 'Ed', 'type' => 1]],
        ]))->assertStatus(400)->assertJsonPath('guest.0.status', 'error')->assertJsonStructure(['guest' => ['0' => ['response' => ['id']]]]);

        $this->save($user, $show, $this->body($show, [$car->id], [
            'guests' => [['id' => $guestId, 'deleted' => true]],
        ]))->assertStatus(400);

        $this->save($user, $show, $this->body($show, [$car->id], [
            'shirts' => [['id' => $shirtId, 'deleted' => true]],
        ]))->assertStatus(400)->assertJsonStructure(['shirt' => ['response' => ['0.id']]]);

        $this->assertSame('Victim', $victimRegistration->guests()->value('first'));
        $this->assertSame(1, $victimRegistration->shirts()->count());
    }

    #[Test]
    public function a_closed_show_rejects_members(): void
    {
        $show = $this->show(['date_registration_deactivation' => now()->subDay()]);
        $user = $this->member();

        $this->save($user, $show, $this->body($show, [$this->car($user)->id]))
            ->assertStatus(400)
            ->assertExactJson(['general' => ['response' => ['closed' => ['Registration is closed']], 'status' => 'error']]);
        $this->quote($user, $show, $this->body($show, [$this->car($user)->id]))->assertStatus(400);
        $this->assertNull($this->storedRegistration($show, $user));
    }

    #[Test]
    public function an_admin_can_register_on_behalf_even_when_closed(): void
    {
        $show = $this->show(['date_registration_deactivation' => now()->subDay()]);
        $admin = $this->admin();
        $member = $this->member();
        $car = $this->car($member);

        $this->save($admin, $show, $this->body($show, [$car->id]), $member->id)
            ->assertOk()
            ->assertJsonPath('registration.user_id', $member->id)
            ->assertJsonPath('targetUser.email', $member->email);

        $this->assertNotNull($this->storedRegistration($show, $member));
        $this->assertNull($this->storedRegistration($show, $admin));
    }

    #[Test]
    public function an_admin_without_user_id_follows_the_member_rules(): void
    {
        $show = $this->show(['date_registration_deactivation' => now()->subDay()]);
        $admin = $this->admin();

        $this->save($admin, $show, $this->body($show, [$this->car($admin)->id]))->assertStatus(400)->assertJsonPath('general.status', 'error');
    }

    #[Test]
    public function an_admin_on_behalf_of_an_unknown_or_deleted_user_gets_404(): void
    {
        $show = $this->show();
        $admin = $this->admin();
        $deleted = $this->member();
        $deleted->delete();

        $this->save($admin, $show, $this->body($show, [1]), 999999)->assertStatus(404);
        $this->save($admin, $show, $this->body($show, [1]), $deleted->id)->assertStatus(404);
    }

    #[Test]
    public function an_admin_on_behalf_cannot_use_the_admins_own_car(): void
    {
        $show = $this->show();
        $admin = $this->admin();
        $member = $this->member();

        $this->save($admin, $show, $this->body($show, [$this->car($admin)->id]), $member->id)->assertStatus(400);
    }

    #[Test]
    public function a_members_user_id_is_ignored(): void
    {
        $show = $this->show();
        $user = $this->member();
        $victim = $this->member();

        $this->save($user, $show, $this->body($show, [$this->car($user)->id]), $victim->id)
            ->assertOk()
            ->assertJsonPath('registration.user_id', $user->id);

        $this->assertNull($this->storedRegistration($show, $victim));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function invalidBodies(): array
    {
        $guest = fn (array $over): array => ['guests' => [array_merge(['first' => 'Ann', 'last' => 'Lee', 'email' => null, 'type' => 1], $over)]];

        return [
            'negative donation' => ['donation', ['donation' => -5]],
            'oversized donation' => ['donation', ['donation' => 1000]],
            'off-list donation' => ['donation', ['donation' => 7]],
            'text donation' => ['donation', ['donation' => 'lots']],
            'unknown arrival time' => ['arrival', ['arrival_time' => 'noon']],
            'missing arrival time' => ['arrival', ['arrival_time' => null]],
            'bad arrival date' => ['arrival', ['arrival_date' => ['data' => '2026-02-30']]],
            'arrival before the show' => ['arrival', ['arrival_date' => ['data' => '2000-01-01']]],
            'missing arrival date' => ['arrival', ['arrival_date' => null]],
            'unknown shirt type' => ['shirt', ['shirts' => [['type' => 999]]]],
            'shirt without type' => ['shirt', ['shirts' => [['id' => null]]]],
            'guest first too long' => ['guest', $guest(['first' => str_repeat('a', 61)])],
            'guest last too long' => ['guest', $guest(['last' => str_repeat('b', 61)])],
            'guest email too long' => ['guest', $guest(['email' => str_repeat('c', 120).'@example.com'])],
            'guest email invalid' => ['guest', $guest(['email' => 'not-an-email'])],
            'guest name not latin1' => ['guest', $guest(['first' => 'Ann😀'])],
            'guest name markup' => ['guest', $guest(['first' => '<script>'])],
            'guest missing last' => ['guest', $guest(['last' => ''])],
            'guest unknown type' => ['guest', $guest(['type' => 9])],
            'guests not a list of objects' => ['general', ['guests' => ['x']]],
            'car id not integer' => ['cars', ['cars' => [['id' => 'abc']]]],
            'duplicate car' => ['cars', ['cars' => [['id' => 5], ['id' => 5]]]],
            'cars missing' => ['cars', ['cars' => null]],
            'extra quantity negative' => ['extras', ['extras' => [['show_extra_id' => 1, 'num_extras' => -1]]]],
            'cancel not boolean' => ['general', ['cancel' => 'maybe']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('invalidBodies')]
    public function invalid_bodies_get_a_per_section_400_and_write_nothing(string $section, array $overrides): void
    {
        $show = $this->show();
        $user = $this->member();
        $car = $this->car($user);

        $response = $this->save($user, $show, $this->body($show, [$car->id], $overrides))->assertStatus(400);

        $json = $response->json();
        $this->assertIsArray($json);
        $this->assertArrayHasKey($section, $json, (string) json_encode($json));
        if ($section === 'guest') {
            $response->assertJsonPath('guest.0.status', 'error');
        } else {
            $response->assertJsonPath($section.'.status', 'error');
        }
        $this->assertNull($this->storedRegistration($show, $user));
    }

    #[Test]
    public function guest_email_must_differ_from_the_registrants(): void
    {
        $show = $this->show();
        $user = $this->member(['email' => 'Owner@Example.com']);

        $this->save($user, $show, $this->body($show, [$this->car($user)->id], [
            'guests' => [['first' => 'Ann', 'last' => 'Lee', 'email' => 'owner@example.com', 'type' => 1]],
        ]))->assertStatus(400)->assertJsonStructure(['guest' => ['0' => ['response' => ['email']]]]);
    }

    #[Test]
    public function guest_names_at_the_column_width_are_stored(): void
    {
        $show = $this->show();
        $user = $this->member();
        $name = str_repeat('é', 60);

        $this->save($user, $show, $this->body($show, [$this->car($user)->id], [
            'guests' => [['first' => $name, 'last' => "D'Arcy-Smith Jr.", 'email' => str_repeat('d', 116).'@example.com', 'type' => 1]],
        ]))->assertOk()->assertJsonPath('registration.guests.0.first', $name);
    }

    #[Test]
    public function extras_of_another_show_disabled_or_out_of_range_are_rejected(): void
    {
        $show = $this->show();
        $other = CarShow::factory()->create();
        $user = $this->member();
        $car = $this->car($user);
        $foreign = $this->extra($other, 'foreign');
        $disabled = $this->extra($show, 'disabled', '5.00', false);
        $ranged = $this->extra($show, 'ranged');
        $ranged->forceFill(['min_value' => 2, 'max_value' => 4])->save();

        foreach ([
            [$foreign->id, 1, 'show_extra_id'],
            [$disabled->id, 1, 'show_extra_id'],
            [$ranged->id, 1, 'num_extras'],
            [$ranged->id, 5, 'num_extras'],
            [999999, 1, 'show_extra_id'],
        ] as [$id, $quantity, $field]) {
            $this->save($user, $show, $this->body($show, [$car->id], [
                'extras' => [$id => ['show_extra_id' => $id, 'num_extras' => $quantity]],
            ]))->assertStatus(400)->assertJsonStructure(['extras' => ['response' => [$id.'.'.$field]]]);
        }

        $this->assertNull($this->storedRegistration($show, $user));
        $this->assertSame(0, DB::table('show_extra_registration')->count());
    }

    #[Test]
    public function an_oversized_total_is_rejected_and_rolled_back(): void
    {
        $show = $this->show();
        $user = $this->member();
        $extra = $this->extra($show, 'yacht', '9000.00');
        $extra->forceFill(['max_value' => 5])->save();

        $this->save($user, $show, $this->body($show, [$this->car($user)->id], [
            'extras' => [$extra->id => ['show_extra_id' => $extra->id, 'num_extras' => 2]],
        ]))->assertStatus(400)->assertJsonPath('general.status', 'error');

        $this->assertNull($this->storedRegistration($show, $user));
    }

    #[Test]
    public function writes_are_throttled_per_user(): void
    {
        $show = $this->show();
        $user = $this->member();

        for ($i = 0; $i < 30; $i++) {
            $this->signIn($user)->postJson(self::SAVE.'?show_id='.$show->id, ['registration' => 'x'])->assertStatus(400);
        }
        $this->signIn($user)->postJson(self::SAVE.'?show_id='.$show->id, ['registration' => 'x'])->assertStatus(429);

        // Another user has their own budget.
        $this->signIn($this->member())->postJson(self::SAVE.'?show_id='.$show->id, ['registration' => 'x'])->assertStatus(400);
    }
}
