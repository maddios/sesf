<?php

namespace Tests\Database\Api\Registration\Write;

use App\Models\CarShow;
use App\Models\Shirt;
use App\Models\ShirtType;
use App\Services\Registration\RegistrationPricing;
use Database\Factories\ShirtTypeFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Per-event shirt offer and prices (show_shirt_type via ShowShirtCatalog) in the registration read, quote, save
 * and invoice. An event without rows behaves exactly as before: every type at shirt_type.price.
 */
class ShowShirtOfferTest extends WriteTestCase
{
    private const S_TEE = ShirtTypeFactory::MENS_S_TSHIRT;   // default 35.00

    private const M_TEE = ShirtTypeFactory::MENS_M_TSHIRT;   // default 35.00

    private const POLO = ShirtTypeFactory::MENS_S_POLO;      // default 55.00

    /**
     * @param  array<int, float|null>  $types  type id => price override (null = default)
     */
    private function offer(CarShow $show, array $types): void
    {
        DB::table('show_shirt_type')->where('show_id', $show->id)->delete();
        foreach ($types as $typeId => $price) {
            DB::table('show_shirt_type')->insert(['show_id' => $show->id, 'shirt_type_id' => $typeId, 'price' => $price]);
        }
    }

    #[Test]
    public function an_event_without_an_offer_lists_every_type_at_its_default_price(): void
    {
        $show = $this->show();
        $user = $this->member();

        $types = $this->signIn($user)->getJson('/event/registration?show_id='.$show->id)->assertOk()->json('shirtTypes');

        $expected = ShirtType::query()->orderBy('id')->get()
            ->map(fn (ShirtType $type) => ['id' => (int) $type->id, 'name' => $type->name, 'price' => $type->price])
            ->all();
        $this->assertSame($expected, $types);
    }

    #[Test]
    public function an_event_with_an_offer_lists_only_its_types_at_the_effective_price(): void
    {
        $show = $this->show();
        $this->offer($show, [self::POLO => 40.0, self::S_TEE => null]);

        $this->signIn($this->member())->getJson('/event/registration?show_id='.$show->id)->assertOk()
            ->assertJsonPath('shirtTypes', [
                ['id' => self::S_TEE, 'name' => 'Mens S T-Shirt', 'price' => '35.00'],
                ['id' => self::POLO, 'name' => 'Mens S Polo shirt', 'price' => '40.00'],
            ]);
    }

    #[Test]
    public function an_old_event_without_an_offer_prices_and_snapshots_exactly_as_before(): void
    {
        $show = $this->show();
        $user = $this->member();
        $body = $this->body($show, [$this->car($user)->id], ['shirts' => [['type' => self::POLO], ['type' => 22]]]);

        $quote = $this->quote($user, $show, $body)->assertOk()->json('payment');
        $this->save($user, $show, $body)->assertOk();

        $price22 = ShirtType::query()->findOrFail(22)->price;
        $this->assertSame(['55.00', $price22], Shirt::query()->where('user_id', $user->id)->orderBy('id')->pluck('price')->all());
        $this->assertSame(RegistrationPricing::decimal(5500 + (int) round((float) $price22 * 100)), $quote['shirts']);
    }

    #[Test]
    public function a_new_shirt_snapshots_the_event_price_and_the_total_uses_it(): void
    {
        $show = $this->show();
        $this->offer($show, [self::POLO => 40.0, self::S_TEE => null]);
        $user = $this->member();
        $body = $this->body($show, [$this->car($user)->id], ['shirts' => [['type' => self::POLO], ['type' => self::S_TEE]]]);

        // car 25 + polo 40 (override) + tee 35 (default)
        $this->quote($user, $show, $body)->assertOk()
            ->assertJsonPath('payment.shirts', '75.00')
            ->assertJsonPath('payment.total', '100.00');
        $this->save($user, $show, $body)->assertOk()->assertJsonPath('registration.payment.total', '100.00');

        $this->assertSame(['40.00', '35.00'], Shirt::query()->where('user_id', $user->id)->orderBy('id')->pluck('price')->all());
        $this->assertSame('100.00', $this->storedRegistration($show, $user)?->amount_owed);
    }

    #[Test]
    public function a_type_the_event_does_not_offer_is_rejected_on_quote_and_save(): void
    {
        $show = $this->show();
        $this->offer($show, [self::S_TEE => null]);
        $user = $this->member();
        $body = $this->body($show, [$this->car($user)->id], ['shirts' => [['type' => self::POLO]]]);

        $this->quote($user, $show, $body)->assertStatus(400)
            ->assertJsonPath('shirt.response', ['0.type' => ['This shirt is not available for this event.']]);
        $this->save($user, $show, $body)->assertStatus(400)->assertJsonStructure(['shirt' => ['response' => ['0.type']]]);
        $this->assertSame(0, Shirt::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function an_order_of_a_type_removed_later_keeps_working_with_its_snapshot_price(): void
    {
        $show = $this->show();
        $user = $this->member();
        $car = $this->car($user);
        $this->save($user, $show, $this->body($show, [$car->id], ['shirts' => [['type' => self::POLO]]]))->assertOk();
        $shirtId = (int) Shirt::query()->where('user_id', $user->id)->value('id');

        // The admin then stops offering polos and lowers the tee price.
        $this->offer($show, [self::S_TEE => 20.0]);

        $read = $this->signIn($user)->getJson('/event/registration?show_id='.$show->id)->assertOk();
        $read->assertJsonPath('registration.shirts', [['id' => $shirtId, 'type' => self::POLO, 'name' => 'Mens S Polo shirt', 'price' => '55.00']]);
        $read->assertJsonPath('registration.payment.shirts', '55.00');
        $this->assertNotContains(self::POLO, array_column($read->json('shirtTypes'), 'id'));

        // Saving again with the kept shirt (and a new guest) is accepted and keeps 55.00.
        $this->save($user, $show, $this->body($show, [$car->id], [
            'shirts' => [['id' => $shirtId, 'type' => self::POLO], ['type' => self::S_TEE]],
        ]))->assertOk()->assertJsonPath('registration.payment.shirts', '75.00');
        $this->assertSame(['55.00', '20.00'], Shirt::query()->where('user_id', $user->id)->orderBy('id')->pluck('price')->all());

        // Changing another shirt TO the removed type is refused.
        $newId = (int) Shirt::query()->where('user_id', $user->id)->where('type_id', self::S_TEE)->value('id');
        $this->save($user, $show, $this->body($show, [$car->id], [
            'shirts' => [['id' => $newId, 'type' => self::POLO]],
        ]))->assertStatus(400)->assertJsonStructure(['shirt' => ['response' => ['0.type']]]);
    }

    #[Test]
    public function a_price_change_never_rewrites_existing_orders(): void
    {
        $show = $this->show();
        $this->offer($show, [self::POLO => 40.0]);
        $user = $this->member();
        $this->save($user, $show, $this->body($show, [$this->car($user)->id], ['shirts' => [['type' => self::POLO]]]))->assertOk();

        $this->offer($show, [self::POLO => 10.0]);
        DB::table('shirt_type')->where('id', self::POLO)->update(['price' => 99]);

        $registration = $this->storedRegistration($show, $user);
        $this->assertNotNull($registration);
        $this->assertSame('40.00', (new RegistrationPricing)->totalsFor($registration)['shirts']);
        $this->assertSame('40.00', Shirt::query()->where('user_id', $user->id)->value('price'));
    }

    #[Test]
    public function a_legacy_line_without_a_snapshot_is_priced_at_the_event_price(): void
    {
        $show = $this->show();
        $this->offer($show, [self::M_TEE => 12.0]);
        $user = $this->member();
        $registration = $this->registration($show, $user);
        $this->shirt($registration, $user, self::M_TEE, null);   // legacy row: price NULL
        $this->shirt($registration, $user, self::POLO, null);    // legacy row of a type no longer offered: its default

        $this->signIn($user)->getJson('/event/registration?show_id='.$show->id)->assertOk()
            ->assertJsonPath('registration.payment.shirts', '67.00');
        $this->signIn($user)->getJson('/member/invoice?registration_id='.$registration->id)->assertOk()
            ->assertJsonPath('shirts.0.price', '12.00')
            ->assertJsonPath('shirts.1.price', '55.00')
            ->assertJsonPath('totals.shirts', '67.00');
    }

    #[Test]
    public function quote_and_invoice_line_items_name_each_car_guest_shirt_and_extra(): void
    {
        $show = $this->show();
        $this->offer($show, [self::POLO => 40.0]);
        $user = $this->member();
        $car = $this->car($user, overrides: ['year' => '1988', 'model' => 'M6', 'nickname' => 'Sharky']);
        $plain = $this->car($user, overrides: ['year' => '1985', 'model' => '635CSi', 'nickname' => null]);
        $extra = $this->extra($show, 'dinner', '12.50');
        $body = $this->body($show, [$car->id, $plain->id], [
            'guests' => [['first' => 'Ann', 'last' => 'Lee', 'type' => 1], ['first' => 'Bo', 'last' => 'Lee', 'type' => 2]],
            'shirts' => [['type' => self::POLO]],
            'extras' => [$extra->id => ['show_extra_id' => $extra->id, 'num_extras' => 2]],
        ]);

        $lines = $this->quote($user, $show, $body)->assertOk()->json('payment.lineItems');
        $this->assertSame(
            [['car', 'Car', 'Sharky (1988 M6)'], ['car', 'Car', '1985 635CSi'], ['guest', 'Adult', 'Ann Lee'], ['guest', 'Child', 'Bo Lee'], ['shirt', 'Shirt', 'Mens S Polo shirt'], ['extra', 'Extra', $extra->name]],
            array_map(fn (array $line) => [$line['kind'], $line['label'], $line['detail']], $lines),
        );

        $this->save($user, $show, $body)->assertOk();
        $registration = $this->storedRegistration($show, $user);
        $this->assertNotNull($registration);
        $invoiceLines = $this->signIn($user)->getJson('/member/invoice?registration_id='.$registration->id)->assertOk()->json('totals.lineItems');
        $this->assertSame(array_column($lines, 'detail'), array_column($invoiceLines, 'detail'));
        $this->assertSame(array_column($lines, 'amount'), array_column($invoiceLines, 'amount'));
    }
}
