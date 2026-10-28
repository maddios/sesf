<?php

namespace App\Services\Admin\Reports;

use App\Support\Admin\AdminQuery;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Streams every row of an AdminQuery list page by page, with the same filters and sort as the
 * screen. Priming the generator (current()) runs the first page, so a 422 from a bad filter is
 * raised before any export bytes are sent.
 */
final class ReportPages
{
    public const PER_PAGE = 100;

    /**
     * @template T of Model
     *
     * @param  Closure(): AdminQuery<T>  $make  a fresh query on the same request
     * @param  class-string  $resource
     * @return Generator<int, array<string, mixed>>
     */
    public static function each(Request $request, Closure $make, string $resource): Generator
    {
        $request->query->set('per_page', (string) self::PER_PAGE);
        $page = 1;

        do {
            $request->query->set('page', (string) $page);
            $result = $make()->paginate($resource);
            foreach ($result['data'] as $row) {
                yield $row;
            }
            $page++;
        } while ($page <= $result['meta']['last_page']);
    }
}
