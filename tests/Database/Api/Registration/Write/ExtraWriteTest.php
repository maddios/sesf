<?php

namespace Tests\Database\Api\Registration\Write;

use App\Models\CarShow;
use App\Models\ShowExtra;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST event/extra?extra_id=<auth_key>[&show_id] {num_extras}.
 */
class ExtraWriteTest extends WriteTestCase
{
    private function rangedExtra(CarShow $show, string $key, int $min = 1, int $max = 10): ShowExtra
    {
        $extra = $this->extra($show, $key, '7.50');
        $extra->forceFill(['min_value' => $min, 'max_value' => $max])->save();

        return $extra;
    }

    #[Test]
    public function it_sets_only_that_extra_and_returns_the_get_payload(): void
    {
        $show = $this->show();
        $user = $this->member();
        $registration = $this->registration($show, $user);
        $this->enterCar($registration, $this->car($user));
        $other = $this->extra($show, 'other');
        $this->addExtraToRegistration($registration, $other, 2);
        $extra = $this->rangedExtra($show, 'dinner-key');

        $response = $this->signIn($user)->postJson(self::EXTRA.'?extra_id=dinner-key&show_id='.$show->id, ['num_extras' => 3])->assertOk();

        $get = $this->signIn($user)->getJson(self::EXTRA.'?extra_id=dinner-key&show_id='.$show->id)->assertOk();
        $this->assertSame($get->json(), $response->json());
        $response->assertJsonPath('registration.extras.'.$extra->id.'.num_extras', 3);
        $response->assertJsonPath('registration.extras.'.$other->id.'.num_extras', 2);
        // car 25 + other 2 x 5 + dinner 3 x 7.50
        $this->assertSame('57.50', $registration->refresh()->amount_owed);

        $this->signIn($user)->postJson(self::EXTRA.'?extra_id=dinner-key', ['num_extras' => 0])->assertOk()
            ->assertJsonMissingPath('registration.extras.'.$extra->id);
        $this->assertSame('35.00', $registration->refresh()->amount_owed);
    }

    #[Test]
    public function a_disabled_extra_can_be_ordered_through_its_key(): void
    {
        $show = $this->show();
        $user = $this->member();
        $this->registration($show, $user);
        $extra = $this->extra($show, 'hidden', '5.00', false);

        $this->signIn($user)->postJson(self::EXTRA.'?extra_id=hidden', ['num_extras' => 1])->assertOk()
            ->assertJsonPath('registration.extras.'.$extra->id.'.num_extras', 1);
    }

    #[Test]
    public function unknown_key_or_wrong_show_is_404(): void
    {
        $show = $this->show();
        $user = $this->member();
        $this->registration($show, $user);
        $this->rangedExtra($show, 'known');

        $this->signIn($user)->postJson(self::EXTRA.'?extra_id=nope', ['num_extras' => 1])->assertStatus(404);
        $this->signIn($user)->postJson(self::EXTRA, ['num_extras' => 1])->assertStatus(404);
        $this->signIn($user)->postJson(self::EXTRA.'?extra_id=known&show_id='.CarShow::factory()->create()->id, ['num_extras' => 1])->assertStatus(404);
    }

    #[Test]
    public function quantity_out_of_range_or_negative_is_400(): void
    {
        $show = $this->show();
        $user = $this->member();
        $this->registration($show, $user);
        $this->rangedExtra($show, 'ranged', 2, 4);

        foreach ([-1, 1, 5, 'x', null, 70000] as $quantity) {
            $this->signIn($user)->postJson(self::EXTRA.'?extra_id=ranged', ['num_extras' => $quantity])
                ->assertStatus(400)
                ->assertJsonPath('extras.status', 'error');
        }
        $this->assertSame(0, DB::table('show_extra_registration')->count());
    }

    #[Test]
    public function a_user_without_a_registration_gets_400(): void
    {
        $show = $this->show();
        $this->rangedExtra($show, 'noreg');

        $this->signIn($this->member())->postJson(self::EXTRA.'?extra_id=noreg', ['num_extras' => 1])
            ->assertStatus(400)
            ->assertJsonPath('general.status', 'error');
        $this->assertSame(0, DB::table('show_extra_registration')->count());
    }
}
