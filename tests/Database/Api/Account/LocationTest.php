<?php

namespace Tests\Database\Api\Account;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class LocationTest extends AccountTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function location(array $overrides = []): array
    {
        return array_merge([
            'address1' => '500 New Street',
            'address2' => 'Unit 4',
            'city' => 'Atlanta',
            'state' => 'GA',
            'zip' => '30309',
        ], $overrides);
    }

    #[Test]
    public function anonymous_requests_get_401(): void
    {
        $this->getJson('/member/location')->assertUnauthorized();
        $this->postJson('/member/location', $this->location())->assertUnauthorized();
    }

    #[Test]
    public function the_member_reads_their_own_address(): void
    {
        $user = $this->makeUser(['address1' => '1 Own Road', 'city' => 'Tampa', 'state' => 'FL', 'zip' => '33602']);

        $this->actingAs($user)->getJson('/member/location')
            ->assertOk()
            ->assertExactJson(['address1' => '1 Own Road', 'address2' => null, 'city' => 'Tampa', 'state' => 'FL', 'zip' => '33602']);
    }

    #[Test]
    public function a_user_id_parameter_cannot_read_another_members_address(): void
    {
        $me = $this->makeUser(['city' => 'Tampa']);
        $other = $this->makeUser(['address1' => 'Other Secret Street', 'city' => 'Secretville']);

        $this->actingAs($me)->getJson('/member/location?user_id='.$other->id)
            ->assertOk()
            ->assertJsonPath('city', 'Tampa')
            ->assertJsonMissing(['Secretville']);
    }

    #[Test]
    public function the_member_saves_their_own_address_and_gets_the_full_member_record(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/member/location', $this->location())
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('city', 'Atlanta')
            ->assertJsonMissingPath('password');

        $user->refresh();
        $this->assertSame('500 New Street', $user->address1);
        $this->assertSame('GA', $user->state);
    }

    #[Test]
    public function a_user_id_in_the_body_cannot_change_another_members_address(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser(['address1' => 'Untouched Lane', 'city' => 'Keep']);

        $this->actingAs($me)->postJson('/member/location', $this->location(['user_id' => $other->id]))->assertOk();

        $other->refresh();
        $this->assertSame('Untouched Lane', $other->address1);
        $this->assertSame('Keep', $other->city);
        $this->assertSame('Atlanta', $me->fresh()->city);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function invalidLocations(): array
    {
        return [
            'address1 missing' => ['address1', null],
            'address1 too short' => ['address1', 'A'],
            'address1 too long' => ['address1', str_repeat('a', 65)],
            'address2 too long' => ['address2', str_repeat('a', 65)],
            'city missing' => ['city', null],
            'state not two letters' => ['state', 'GEO'],
            'state with digits' => ['state', 'G2'],
            'zip too short' => ['zip', '123'],
            'zip with punctuation' => ['zip', '30-309'],
            'zip missing' => ['zip', null],
        ];
    }

    #[Test]
    #[DataProvider('invalidLocations')]
    public function invalid_addresses_are_rejected_and_not_saved(string $field, mixed $value): void
    {
        $user = $this->makeUser(['city' => 'Stable']);

        $this->actingAs($user)->postJson('/member/location', $this->location([$field => $value]))
            ->assertStatus(400)
            ->assertJsonIsArray();

        $this->assertSame('Stable', $user->fresh()->city);
    }

    #[Test]
    public function a_post_without_a_csrf_token_gets_419(): void
    {
        $this->app->instance('env', 'local');
        $user = $this->makeUser();

        $this->actingAs($user)->withHeader('Accept', 'application/json')
            ->post('/member/location', $this->location())
            ->assertStatus(419);
    }
}
