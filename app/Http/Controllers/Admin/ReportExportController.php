<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\ReportExtraResource;
use App\Http\Resources\Admin\ReportMemberResource;
use App\Http\Resources\Admin\ReportRegistrationResource;
use App\Services\Admin\Reports\EventSummaryReport;
use App\Services\Admin\Reports\ExtraReport;
use App\Services\Admin\Reports\MemberReport;
use App\Services\Admin\Reports\ReportPages;
use App\Services\Admin\Reports\SpreadsheetMl;
use App\Services\Admin\Reports\StyledCell;
use App\Support\Admin\AuditLog;
use App\Support\Admin\ShowParam;
use Closure;
use Generator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * admin-api/reports/*.xls: SpreadsheetML exports of the report screens, streamed page by page with
 * the same filters and sort as the screen. The event summary workbook keeps the legacy sheets,
 * columns and cell styles (event_summary_export.blade.php). Each export is audited when it starts.
 */
final class ReportExportController extends Controller
{
    private const RESOURCE = 'report';

    public function members(Request $request): StreamedResponse
    {
        $pages = self::prime(fn () => ReportPages::each(
            $request,
            fn () => MemberReport::query($request),
            ReportMemberResource::class,
        ));
        AuditLog::record('report.export', self::RESOURCE, [], [], ['report' => 'members'], $request);

        return self::stream('members.xls', function () use ($pages): void {
            echo SpreadsheetMl::sheet('Members');
            echo SpreadsheetMl::row(['First Name', 'Last Name', 'Email']);
            foreach ($pages as $row) {
                echo SpreadsheetMl::row([$row['first'], $row['last'], $row['email']]);
            }
            echo SpreadsheetMl::endSheet();
        });
    }

    public function extras(Request $request): StreamedResponse
    {
        $showId = ShowParam::required($request);
        $pages = self::prime(fn () => ReportPages::each(
            $request,
            fn () => ExtraReport::query($showId, $request),
            ReportExtraResource::class,
        ));
        AuditLog::record('report.export', self::RESOURCE, [$showId], [], ['report' => 'extras']);

        return self::stream('extras-'.$showId.'.xls', function () use ($pages): void {
            echo SpreadsheetMl::sheet('Extras');
            echo SpreadsheetMl::row(['Id', 'Name', 'Num Total', 'Num Paid']);
            foreach ($pages as $row) {
                echo SpreadsheetMl::row([$row['id'], $row['name'], $row['num_total'], $row['num_paid']]);
            }
            echo SpreadsheetMl::endSheet();
        });
    }

    public function eventSummary(Request $request, EventSummaryReport $report): StreamedResponse
    {
        $showId = ShowParam::required($request);
        $maxima = $report->maxima($showId);
        $pages = fn (): Generator => ReportPages::each(
            $request,
            fn () => EventSummaryReport::query($showId, $request),
            ReportRegistrationResource::class,
        );
        $first = self::prime($pages);
        AuditLog::record('report.export', self::RESOURCE, [$showId], [], ['report' => 'event_summary']);

        return self::stream('event_summary.xls', function () use ($first, $pages, $report, $showId, $maxima): void {
            echo SpreadsheetMl::sheet('Data');
            echo SpreadsheetMl::row(self::dataHeader($maxima));
            foreach ($first as $row) {
                echo SpreadsheetMl::row(self::dataRow($row, $maxima));
            }
            echo SpreadsheetMl::endSheet();

            echo SpreadsheetMl::sheet('Guests');
            echo SpreadsheetMl::row(['Reg #', 'First', 'Last', 'E-Mail', 'User First Name', 'User First Name']);
            foreach ($pages() as $row) {
                foreach ($row['guests'] as $guest) {
                    echo SpreadsheetMl::row([$row['invoice_id'], $guest['first'], $guest['last'], $guest['email'], $row['first'], $row['last']]);
                }
            }
            echo SpreadsheetMl::endSheet();

            echo SpreadsheetMl::sheet('Shirts');
            echo SpreadsheetMl::row(['Reg #', 'First', 'Last', 'Shirt Type']);
            $counts = [];
            foreach ($pages() as $row) {
                foreach ($row['shirts'] as $type) {
                    $label = $type ?? 'Unknown';
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                    echo SpreadsheetMl::row([$row['invoice_id'], $row['first'], $row['last'], $type ?? '']);
                }
            }
            echo SpreadsheetMl::row([]);
            echo SpreadsheetMl::row([]);
            echo SpreadsheetMl::row(['Total tshirts ordered by type']);
            echo SpreadsheetMl::row(['Type', '', 'Count']);
            foreach ($counts as $type => $count) {
                echo SpreadsheetMl::row([$type, '', $count]);
            }
            echo SpreadsheetMl::endSheet();

            $totals = $report->totals($showId);
            echo SpreadsheetMl::sheet('Summary');
            echo SpreadsheetMl::row(['Total registrants', 'Total guests', 'Paid registrants', 'Paid guests', 'Total extras', 'Total extras paid', 'Number of tshirts ordered', 'Number of tshirts paid']);
            echo SpreadsheetMl::row(array_values($totals));
            echo SpreadsheetMl::endSheet();
        });
    }

    /**
     * @param  array{guests: int, shirts: int}  $maxima
     * @return list<string>
     */
    private static function dataHeader(array $maxima): array
    {
        $header = ['Reg #', 'First', 'Last', 'E-Mail', 'Address1', 'Address2', 'City', 'State', 'Zip', 'Paid',
            'User Signed Waiver', 'Guests Signed Waiver', 'Judging', 'Cars'];
        for ($i = 1; $i <= $maxima['guests']; $i++) {
            $header[] = 'Guest '.$i;
        }
        for ($i = 1; $i <= $maxima['shirts']; $i++) {
            $header[] = 'Shirt '.$i;
        }

        return [...$header, 'Extras', 'Arrival Time'];
    }

    /**
     * One registration row, with the legacy styles: unpaid and unsigned cells in red text, a judged
     * row's Judging cell in light orange, and an unsigned guest's name in red.
     *
     * @param  array<string, mixed>  $row
     * @param  array{guests: int, shirts: int}  $maxima
     * @return list<mixed>
     */
    private static function dataRow(array $row, array $maxima): array
    {
        $guestsUnsigned = array_filter($row['guests'], fn (array $guest) => ! $guest['signed']) !== [];
        $cells = [
            $row['invoice_id'], $row['first'], $row['last'], $row['email'], $row['address1'], $row['address2'],
            $row['city'], $row['state'], $row['zip'],
            self::yesNo($row['is_paid'], red: ! $row['is_paid']),
            self::yesNo($row['user_signed_waiver'], red: ! $row['waiver_signed']),
            self::yesNo(! $guestsUnsigned, red: $guestsUnsigned),
            self::yesNo($row['judged'], orange: $row['judged']),
            implode('|', $row['cars']),
        ];
        for ($i = 0; $i < $maxima['guests']; $i++) {
            $guest = $row['guests'][$i] ?? null;
            $cells[] = $guest !== null && $guest['signed'] ? $guest['name'] : new StyledCell($guest['name'] ?? '', StyledCell::RED_TEXT);
        }
        for ($i = 0; $i < $maxima['shirts']; $i++) {
            $cells[] = $row['shirts'][$i] ?? '';
        }
        $cells[] = implode('|', array_map(fn (array $extra) => $extra['count'].'x'.$extra['name'], $row['extras']));
        $cells[] = trim($row['arrival_time'].' '.$row['arrival_date']);

        return $cells;
    }

    private static function yesNo(bool $value, bool $red = false, bool $orange = false): mixed
    {
        $text = $value ? 'Yes' : 'No';
        if ($red) {
            return new StyledCell($text, StyledCell::RED_TEXT);
        }

        return $orange ? new StyledCell($text, StyledCell::LIGHT_ORANGE) : $text;
    }

    /**
     * Runs the first page now, so a filter or show error is a JSON 422 before the download starts.
     *
     * @param  Closure(): Generator  $make
     */
    private static function prime(Closure $make): Generator
    {
        $generator = $make();
        $generator->current();

        return $generator;
    }

    private static function stream(string $filename, Closure $body): StreamedResponse
    {
        return response()->streamDownload(function () use ($body): void {
            echo SpreadsheetMl::open();
            $body();
            echo SpreadsheetMl::close();
        }, $filename, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
