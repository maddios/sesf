<?php

namespace Tests\Database\Admin;

use App\Models\CarShow;
use App\Models\Expense;
use App\Models\ShowRegistration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * admin-api/expenses: the per-event expense tracker (legacy /expense_tracker, which had no auth).
 */
class ExpenseAdminTest extends AdminTestCase
{
    private const BASE = '/admin-api/expenses';

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
        $show = CarShow::factory()->create();
        $expense = Expense::factory()->create(['show_id' => $show->id, 'amount' => 10]);
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);
        $expect = fn (int $ok) => $status === 200 ? $ok : $status;

        $as()->getJson(self::BASE.'?show_id='.$show->id)->assertStatus($expect(200));
        $as()->postJson(self::BASE, ['show_id' => $show->id, 'description' => 'Tent', 'amount' => '5', 'type' => 'expense'])->assertStatus($expect(201));
        $as()->putJson(self::BASE.'/'.$expense->id, ['description' => 'Tent', 'amount' => '6', 'type' => 'expense'])->assertStatus($expect(200));
        $as()->deleteJson(self::BASE.'/'.$expense->id)->assertStatus($expect(204));

        if ($status !== 200) {
            $this->assertSame(1, Expense::query()->count());
            $this->assertSame('10.00', $expense->refresh()->amount);
        }
    }

    #[Test]
    public function the_list_has_the_legacy_order_and_totals(): void
    {
        $show = CarShow::factory()->create();
        $other = CarShow::factory()->create();
        $income = Expense::factory()->create(['show_id' => $show->id, 'description' => 'Sponsor check', 'amount' => -200, 'created_at' => '2026-01-01 10:00:00']);
        $late = Expense::factory()->create(['show_id' => $show->id, 'description' => 'Food', 'amount' => 150.25, 'created_at' => '2026-03-01 10:00:00']);
        $early = Expense::factory()->create(['show_id' => $show->id, 'description' => 'Tent', 'amount' => 300.10, 'created_at' => '2026-02-01 10:00:00']);
        Expense::factory()->create(['show_id' => $other->id, 'amount' => 999]);
        ShowRegistration::factory()->create(['show_id' => $show->id, 'amount_received' => 100.10, 'amount_received_actual' => 95.50]);
        ShowRegistration::factory()->create(['show_id' => $show->id, 'amount_received' => 50, 'amount_received_actual' => null, 'is_cancelled' => true]);
        ShowRegistration::factory()->create(['show_id' => $other->id, 'amount_received' => 1000, 'amount_received_actual' => 1000]);

        $response = $this->actingAs($this->admin())->getJson(self::BASE.'?show_id='.$show->id)->assertOk();

        $this->assertSame([$early->id, $late->id, $income->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.amount', '300.10')
            ->assertJsonPath('data.0.type', 'expense')
            ->assertJsonPath('data.0.absolute_amount', '300.10')
            ->assertJsonPath('data.2.amount', '-200.00')
            ->assertJsonPath('data.2.type', 'income')
            ->assertJsonPath('data.2.absolute_amount', '200.00')
            ->assertJsonPath('totals', [
                'registration_fees' => '150.10',
                'net_received' => '95.50',
                'total_expenses' => '250.35',
                'gross' => '-154.85',
            ]);
    }

    #[Test]
    public function an_event_without_lines_has_zero_totals(): void
    {
        $show = CarShow::factory()->create();

        $this->actingAs($this->admin())->getJson(self::BASE.'?show_id='.$show->id)
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('totals', ['registration_fees' => '0.00', 'net_received' => '0.00', 'total_expenses' => '0.00', 'gross' => '0.00']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badShowIds(): array
    {
        return ['missing' => [''], 'not a number' => ['abc'], 'injection' => ['1;drop table expense'], 'unknown' => ['999999']];
    }

    #[Test]
    #[DataProvider('badShowIds')]
    public function the_list_needs_an_existing_event(string $showId): void
    {
        $this->actingAs($this->admin())->getJson(self::BASE.'?show_id='.urlencode($showId))
            ->assertStatus(422)
            ->assertJsonValidationErrors('show_id');
    }

    #[Test]
    public function an_expense_is_stored_positive_and_income_negative(): void
    {
        $show = CarShow::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(self::BASE, ['show_id' => $show->id, 'description' => ' Tent rental ', 'amount' => '125.5', 'type' => 'expense'])
            ->assertCreated()
            ->assertJsonPath('data.amount', '125.50')
            ->assertJsonPath('data.description', 'Tent rental')
            ->assertJsonPath('data.show_id', $show->id);
        $this->actingAs($admin)->postJson(self::BASE, ['show_id' => $show->id, 'description' => 'Raffle', 'amount' => '80', 'type' => 'income'])
            ->assertCreated()
            ->assertJsonPath('data.amount', '-80.00')
            ->assertJsonPath('data.type', 'income');
        $this->actingAs($admin)->postJson(self::BASE, ['show_id' => $show->id, 'amount' => '0', 'type' => 'income'])
            ->assertCreated()
            ->assertJsonPath('data.amount', '0.00')
            ->assertJsonPath('data.description', '');

        $this->assertEqualsCanonicalizing(['125.50', '-80.00', '0.00'], Expense::query()->pluck('amount')->all());
    }

    #[Test]
    public function an_update_changes_description_amount_and_sign_but_not_the_event(): void
    {
        $show = CarShow::factory()->create();
        $other = CarShow::factory()->create();
        $expense = Expense::factory()->create(['show_id' => $show->id, 'amount' => 10]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$expense->id, ['show_id' => $other->id, 'description' => 'Refund', 'amount' => '12.34', 'type' => 'income'])
            ->assertOk()
            ->assertJsonPath('data.amount', '-12.34');

        $expense->refresh();
        $this->assertSame('-12.34', $expense->amount);
        $this->assertSame('Refund', $expense->description);
        $this->assertSame($show->id, $expense->show_id);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInput(): array
    {
        return [
            'amount missing' => [['type' => 'expense'], 'amount'],
            'amount negative' => [['amount' => '-5', 'type' => 'expense'], 'amount'],
            'amount three decimals' => [['amount' => '1.234', 'type' => 'expense'], 'amount'],
            'amount over the column' => [['amount' => '1000000.00', 'type' => 'expense'], 'amount'],
            'amount not a number' => [['amount' => 'ten', 'type' => 'expense'], 'amount'],
            'type missing' => [['amount' => '5'], 'type'],
            'type unknown' => [['amount' => '5', 'type' => 'refund'], 'type'],
            'description too long' => [['amount' => '5', 'type' => 'expense', 'description' => str_repeat('a', 256)], 'description'],
            'description not latin1' => [['amount' => '5', 'type' => 'expense', 'description' => 'Tent 🎪'], 'description'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[Test]
    #[DataProvider('invalidInput')]
    public function invalid_input_is_a_422(array $input, string $field): void
    {
        $show = CarShow::factory()->create();
        $expense = Expense::factory()->create(['show_id' => $show->id]);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(self::BASE, ['show_id' => $show->id] + $input)->assertStatus(422)->assertJsonValidationErrors($field);
        $this->actingAs($admin)->putJson(self::BASE.'/'.$expense->id, $input)->assertStatus(422)->assertJsonValidationErrors($field);
        $this->assertSame(1, Expense::query()->count());
    }

    #[Test]
    public function widths_accept_the_column_maximums(): void
    {
        $show = CarShow::factory()->create();

        $this->actingAs($this->admin())->postJson(self::BASE, ['show_id' => $show->id, 'description' => str_repeat('a', 255), 'amount' => '999999.99', 'type' => 'expense'])
            ->assertCreated();
    }

    #[Test]
    public function a_create_needs_an_existing_event(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(self::BASE, ['amount' => '5', 'type' => 'expense'])->assertStatus(422)->assertJsonValidationErrors('show_id');
        $this->actingAs($admin)->postJson(self::BASE, ['show_id' => 999999, 'amount' => '5', 'type' => 'expense'])->assertStatus(422)->assertJsonValidationErrors('show_id');
    }

    #[Test]
    public function missing_lines_are_404(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->putJson(self::BASE.'/999999', ['amount' => '1', 'type' => 'expense'])->assertNotFound();
        $this->actingAs($admin)->deleteJson(self::BASE.'/999999')->assertNotFound();
    }

    #[Test]
    public function writes_are_audited_without_the_description_text(): void
    {
        $show = CarShow::factory()->create();
        $admin = $this->admin();

        $id = $this->actingAs($admin)->postJson(self::BASE, ['show_id' => $show->id, 'description' => 'SECRET-MEMO', 'amount' => '5', 'type' => 'expense'])->json('data.id');
        $this->actingAs($admin)->putJson(self::BASE.'/'.$id, ['description' => 'SECRET-MEMO-2', 'amount' => '6', 'type' => 'expense'])->assertOk();
        $this->actingAs($admin)->deleteJson(self::BASE.'/'.$id)->assertNoContent();

        $entries = $this->auditEntries();
        $this->assertSame(['expense.create', 'expense.update', 'expense.delete'], array_column($entries, 'action'));
        $this->assertSame([$id], $entries[0]['ids']);
        $this->assertSame(['old' => '5.00', 'new' => '6.00'], $entries[1]['changes']['amount']);
        $this->assertStringNotContainsString('SECRET-MEMO', $this->auditText());
        $this->assertNull(Expense::query()->find($id));
    }

    #[Test]
    public function the_legacy_url_redirects_to_the_spa_page(): void
    {
        $this->get('/expense_tracker?show_id=12')->assertRedirect('/#/admin/expenses?show_id=12');
        $this->get('/expense_tracker')->assertRedirect('/#/admin/expenses');
        $this->get('/expense_tracker?show_id=1%3Bdrop')->assertRedirect('/#/admin/expenses');
    }
}
