<?php

namespace Tests\Database\Admin;

use App\Models\ShowExtra;
use App\Models\ShowExtraRegistration;
use App\Models\User;
use App\Services\Admin\Reports\SpreadsheetMl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * admin-api/reports: members, event summary and extras lists, and their SpreadsheetML exports.
 */
class ReportAdminTest extends AdminTestCase
{
    use RegistrationFixtures;

    private const BASE = '/admin-api/reports';

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
    public function every_report_and_export_is_admin_only(string $who, int $status): void
    {
        $show = $this->registrationShow();
        $this->registrationWithCarAndGuest($show);
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);
        $expect = fn (int $ok) => $status === 200 ? $ok : $status;
        $id = $show->id;

        $as()->getJson(self::BASE.'/members')->assertStatus($expect(200));
        $as()->withHeaders(self::JSON)->get(self::BASE.'/members/export.xls')->assertStatus($expect(200));
        $as()->getJson(self::BASE.'/event-summary?show_id='.$id)->assertStatus($expect(200));
        $as()->getJson(self::BASE.'/event-summary/totals?show_id='.$id)->assertStatus($expect(200));
        $as()->withHeaders(self::JSON)->get(self::BASE.'/event-summary/export.xls?show_id='.$id)->assertStatus($expect(200));
        $as()->getJson(self::BASE.'/extras?show_id='.$id)->assertStatus($expect(200));
        $as()->withHeaders(self::JSON)->get(self::BASE.'/extras/export.xls?show_id='.$id)->assertStatus($expect(200));
    }

    #[Test]
    public function members_can_be_filtered_by_event_payment_and_preferences(): void
    {
        $show = $this->registrationShow();
        $paid = $this->registrationWithCarAndGuest($show);
        $paid->forceFill(['is_paid' => true])->saveQuietly();
        $unpaid = $this->registrationWithCarAndGuest($show);
        $elsewhere = $this->registrationWithCarAndGuest($this->registrationShow());
        User::query()->whereKey($paid->user_id)->first()?->preferences()->update(['allow_system_email' => true]);
        User::query()->whereKey($unpaid->user_id)->first()?->preferences()->update(['allow_system_email' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(self::BASE.'/members?filter[show_id]='.$show->id)->assertOk()->assertJsonPath('meta.total', 2);
        $this->actingAs($admin)->getJson(self::BASE.'/members?filter[show_id]='.$show->id.'&filter[is_paid]=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($admin)->getJson(self::BASE.'/members?filter[allow_system_email]=0')->assertOk()->assertJsonFragment(['id' => $unpaid->user_id]);
        $this->actingAs($admin)->getJson(self::BASE.'/members?filter[show_id]='.$show->id.'&sort=email;drop')->assertStatus(422);
        $this->actingAs($admin)->getJson(self::BASE.'/members?filter[is_paid]=2')->assertStatus(422)->assertJsonValidationErrors('filter.is_paid');
        $this->assertNotSame($elsewhere->user_id, $paid->user_id);
    }

    #[Test]
    public function the_event_summary_totals_match_the_legacy_formulas(): void
    {
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);
        $registration->forceFill(['is_paid' => true])->saveQuietly();
        $extra = ShowExtra::factory()->create(['show_id' => $show->id]);
        ShowExtraRegistration::factory()->create(['show_extra_id' => $extra->id, 'registration_id' => $registration->id]);

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'/event-summary/totals?show_id='.$show->id)
            ->assertOk()
            ->assertJsonPath('data.registrations', 1)
            ->assertJsonPath('data.guests', 1)
            ->assertJsonPath('data.paid_registrations', 1)
            ->assertJsonPath('data.paid_guests', 1)
            ->assertJsonPath('data.extras', 1)
            ->assertJsonPath('data.paid_extras', 1);
    }

    #[Test]
    public function the_event_summary_lists_cars_guests_and_extras_per_registration(): void
    {
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);
        $extra = ShowExtra::factory()->create(['show_id' => $show->id, 'name' => 'Lunch']);
        ShowExtraRegistration::factory()->quantity(2)->create(['show_extra_id' => $extra->id, 'registration_id' => $registration->id]);

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'/event-summary?show_id='.$show->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $registration->id)
            ->assertJsonCount(1, 'data.0.guests')
            ->assertJsonCount(1, 'data.0.cars');
    }

    #[Test]
    public function the_extras_summary_sums_quantities_bought_and_paid(): void
    {
        $show = $this->registrationShow();
        $paid = $this->registrationWithCarAndGuest($show);
        $paid->forceFill(['is_paid' => true])->saveQuietly();
        $unpaid = $this->registrationWithCarAndGuest($show);
        $extra = ShowExtra::factory()->create(['show_id' => $show->id, 'name' => 'Shirt']);
        ShowExtraRegistration::factory()->quantity(2)->create(['show_extra_id' => $extra->id, 'registration_id' => $paid->id]);
        ShowExtraRegistration::factory()->quantity(3)->create(['show_extra_id' => $extra->id, 'registration_id' => $unpaid->id]);

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'/extras?show_id='.$show->id)
            ->assertOk()
            ->assertJsonPath('data.0.num_total', 5)
            ->assertJsonPath('data.0.num_paid', 2);
    }

    #[Test]
    public function report_lists_need_a_known_event(): void
    {
        $this->actingAs($this->admin())->getJson(self::BASE.'/extras')->assertStatus(422)->assertJsonValidationErrors('show_id');
        $this->actingAs($this->admin())->getJson(self::BASE.'/event-summary?show_id=999999')->assertStatus(422)->assertJsonValidationErrors('show_id');
    }

    #[Test]
    public function the_extras_export_is_spreadsheetml_and_audited(): void
    {
        $show = $this->registrationShow();
        ShowExtra::factory()->create(['show_id' => $show->id, 'name' => '=HYPERLINK("http://evil.example")']);

        $response = $this->actingAs($this->admin())->withHeaders(self::JSON)->get(self::BASE.'/extras/export.xls?show_id='.$show->id);

        $response->assertOk();
        $this->assertStringContainsString('application/vnd.ms-excel', (string) $response->headers->get('Content-Type'));
        $body = $response->streamedContent();
        $this->assertStringContainsString('<Data ss:Type="String">&apos;=HYPERLINK', $body);
        $this->assertStringNotContainsString('<Data ss:Type="String">=HYPERLINK', $body);
        $this->assertContains('report.export', array_column($this->auditEntries(), 'action'));
    }

    #[Test]
    public function the_members_export_streams_every_page(): void
    {
        $show = $this->registrationShow();
        for ($i = 0; $i < 3; $i++) {
            $this->registrationWithCarAndGuest($show);
        }

        $body = $this->actingAs($this->admin())->withHeaders(self::JSON)->get(self::BASE.'/members/export.xls?filter[show_id]='.$show->id)->streamedContent();

        $this->assertSame(3, substr_count($body, '<Row>') - 1);
    }

    #[Test]
    public function the_event_summary_export_has_its_four_sheets(): void
    {
        $show = $this->registrationShow();
        $this->registrationWithCarAndGuest($show);

        $body = $this->actingAs($this->admin())->withHeaders(self::JSON)->get(self::BASE.'/event-summary/export.xls?show_id='.$show->id)->streamedContent();

        foreach (['Data', 'Guests', 'Shirts', 'Summary'] as $sheet) {
            $this->assertStringContainsString('ss:Name="'.$sheet.'"', $body);
        }
    }

    #[Test]
    public function an_unknown_event_is_a_json_422_not_a_download(): void
    {
        $this->actingAs($this->admin())
            ->withHeaders(self::JSON)
            ->get(self::BASE.'/event-summary/export.xls?show_id=999999')
            ->assertStatus(422)
            ->assertHeaderMissing('Content-Disposition');
    }

    #[Test]
    public function spreadsheetml_escapes_markup_and_formula_prefixes(): void
    {
        $markup = SpreadsheetMl::cell("<b>&\x01");

        $this->assertStringContainsString('&lt;b&gt;&amp;', $markup);
        $this->assertStringNotContainsString("\x01", $markup);
        $this->assertStringContainsString('&apos;@sum', SpreadsheetMl::cell('@sum'));
        $this->assertSame('<Cell><Data ss:Type="Number">12.5</Data></Cell>', SpreadsheetMl::cell(12.5));
    }

    #[Test]
    public function the_event_summary_export_keeps_the_legacy_file_styles_and_sheets(): void
    {
        $show = $this->registrationShow();
        $this->registrationWithCarAndGuest($show);

        $response = $this->actingAs($this->admin())->withHeaders(self::JSON)->get(self::BASE.'/event-summary/export.xls?show_id='.$show->id);

        $response->assertOk();
        $this->assertStringContainsString('filename=event_summary.xls', (string) $response->headers->get('Content-Disposition'));
        $body = $response->streamedContent();
        $this->assertStringContainsString('<Style ss:ID="RedText">', $body);
        $this->assertStringContainsString('<Style ss:ID="LightOrangeBg">', $body);
        $this->assertStringContainsString('<Cell ss:StyleID="RedText"><Data ss:Type="String">No</Data>', $body);
        $this->assertStringContainsString('<Data ss:Type="String">User First Name</Data>', $body);
        $this->assertSame(2, substr_count($body, '<Data ss:Type="String">User First Name</Data>'));
        $this->assertStringContainsString('Total tshirts ordered by type', $body);
    }

    #[Test]
    public function the_event_summary_export_styles_paid_and_judged_cells_as_legacy(): void
    {
        $show = $this->registrationShow();
        $paid = $this->registrationWithCarAndGuest($show);
        $paid->forceFill(['is_paid' => true])->saveQuietly();

        $body = $this->actingAs($this->admin())->withHeaders(self::JSON)
            ->get(self::BASE.'/event-summary/export.xls?show_id='.$show->id)
            ->streamedContent();

        $this->assertStringContainsString('<Cell><Data ss:Type="String">Yes</Data></Cell>', $body);
        $this->assertStringNotContainsString('<Cell ss:StyleID="LightOrangeBg">', $body);
    }

    #[Test]
    public function the_waiver_list_and_summary_show_the_same_guest_count_as_the_export(): void
    {
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'/event-summary?show_id='.$show->id)
            ->assertOk()
            ->assertJsonCount($registration->guests()->count(), 'data.0.guests');
    }

    #[Test]
    public function the_default_event_is_the_one_with_the_latest_end_date(): void
    {
        $older = $this->registrationShow(['date_end' => '2001-01-01']);
        $latest = $this->registrationShow(['date_end' => '2099-01-01']);

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'/default-event')
            ->assertOk()
            ->assertJsonPath('data.id', $latest->id);
        $this->assertNotSame($older->id, $latest->id);
    }
}
