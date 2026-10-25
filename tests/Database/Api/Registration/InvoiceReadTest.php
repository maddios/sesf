<?php

namespace Tests\Database\Api\Registration;

use App\Models\ShowRegistration;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET member/invoice-list and member/invoice (legacy MemberProfile::getInvoiceList, getInvoice).
 */
class InvoiceReadTest extends RegistrationTestCase
{
    private const LIST = '/member/invoice-list';

    private const INVOICE = '/member/invoice';

    #[Test]
    public function anonymous_requests_get_401(): void
    {
        $this->getJson(self::LIST.'?user_id=1')->assertStatus(401);
        $this->getJson(self::INVOICE.'?registration_id=1')->assertStatus(401);
    }

    #[Test]
    public function the_invoice_list_returns_the_members_uncancelled_registrations_newest_first(): void
    {
        $user = $this->member();
        $older = $this->registration($this->show(), $user, ['created_at' => now()->subDays(2)]);
        $newer = $this->registration($this->show(), $user, ['created_at' => now()->subDay()]);
        $this->registration($this->show(), $user, ['is_cancelled' => true]);
        $this->registration($this->show(), $this->member()); // someone else

        $this->signIn($user)
            ->getJson(self::LIST.'?user_id='.$user->id)
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $newer->id)
            ->assertJsonPath('1.id', $older->id)
            ->assertJsonStructure([['id', 'name']]);
    }

    #[Test]
    public function a_non_admin_cannot_list_another_members_invoices(): void
    {
        $me = $this->member();
        $other = $this->member();
        $this->registration($this->show(), $other);

        $this->signIn($me)->getJson(self::LIST.'?user_id='.$other->id)->assertStatus(401);
    }

    #[Test]
    public function an_admin_can_list_another_members_invoices(): void
    {
        $other = $this->member();
        $this->registration($this->show(), $other);

        $this->signIn($this->admin())
            ->getJson(self::LIST.'?user_id='.$other->id)
            ->assertOk()
            ->assertJsonCount(1);
    }

    #[Test]
    public function the_owner_gets_the_invoice_with_names_and_totals(): void
    {
        $show = $this->show();
        $user = $this->member();
        $registration = $this->registration($show, $user, ['donation' => 5, 'discount' => 0, 'amount_received' => 0]);
        $this->enterCar($registration, $this->car($user));
        $this->guest($registration);
        $this->shirt($registration, $user);

        $this->signIn($user)
            ->getJson(self::INVOICE.'?registration_id='.$registration->id)
            ->assertOk()
            ->assertJsonStructure([
                'id', 'show_id', 'user_id', 'invoice_id', 'is_paid', 'donation', 'discount',
                'amount_owed', 'amount_received',
                'car_show' => ['id', 'name', 'profileURL', 'regOpen'],
                'cars' => [['id', 'name', 'judging_type']],
                'guests' => [['id', 'fullName', 'type']],
                'shirts' => [['id', 'type' => ['id', 'name'], 'price']],
                'extras',
                'totals' => ['source', 'lineItems', 'cars', 'guests', 'shirts', 'extras', 'donation', 'total', 'received', 'discount', 'owed'],
            ])
            ->assertJsonPath('totals.source', 'computed')
            // car 25 + guest 20 (no free slot: one car gives no free guest) + shirt 35 + donation 5 = 85
            ->assertJsonPath('totals.total', '85.00')
            ->assertJsonPath('totals.owed', '85.00')
            ->assertJsonPath('totals.donation', '5.00');
    }

    #[Test]
    public function an_admin_can_read_any_invoice(): void
    {
        $registration = $this->registration($this->show(), $this->member());

        $this->signIn($this->admin())
            ->getJson(self::INVOICE.'?registration_id='.$registration->id)
            ->assertOk()
            ->assertJsonPath('id', $registration->id);
    }

    #[Test]
    public function another_member_gets_the_same_404_as_the_owner_would_for_an_unknown_id(): void
    {
        $registration = $this->registration($this->show(), $this->member());
        $intruder = $this->member();

        $this->signIn($intruder)
            ->getJson(self::INVOICE.'?registration_id='.$registration->id)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Not found.');
    }

    #[Test]
    public function an_unknown_registration_id_is_404(): void
    {
        $this->signIn($this->member())
            ->getJson(self::INVOICE.'?registration_id=999999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Not found.');
    }

    #[Test]
    public function a_cancelled_registration_is_not_found_even_for_its_owner(): void
    {
        $user = $this->member();
        $registration = $this->registration($this->show(), $user, ['is_cancelled' => true]);

        $this->signIn($user)
            ->getJson(self::INVOICE.'?registration_id='.$registration->id)
            ->assertStatus(404);
    }

    #[Test]
    public function reading_an_invoice_does_not_write_amount_owed(): void
    {
        $user = $this->member();
        $registration = $this->registration($this->show(), $user, ['amount_owed' => 0]);
        $this->enterCar($registration, $this->car($user));

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        $this->signIn($user)->getJson(self::INVOICE.'?registration_id='.$registration->id)->assertOk();
        $writes = array_filter(
            DB::connection()->getQueryLog(),
            fn (array $query): bool => preg_match('/^\s*(insert|update|delete)\b/i', $query['query']) === 1,
        );
        DB::connection()->disableQueryLog();

        $this->assertSame([], array_values($writes));
        $this->assertSame('0.00', (string) ShowRegistration::query()->findOrFail($registration->id)->amount_owed);
    }
}
