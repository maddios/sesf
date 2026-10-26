<?php

namespace App\Support\Admin;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Server-side list endpoint helper for admin-api index routes.
 *
 * Request parameters (all optional):
 *  - page (int >= 1, default 1), per_page (1..100, default 25)
 *  - sort (a whitelisted column), direction (asc|desc)
 *  - search (<= 100 chars): LIKE across the searchable columns, wildcards escaped
 *  - filter[col]=v (eq) or filter[col][op]=v, op one of eq, like, in, between, null.
 *    in and between take a comma list or an array; null takes 1 (IS NULL) or 0 (IS NOT NULL).
 *
 * Every column comes from a whitelist given by the controller; request values are only ever bound
 * as parameters. Anything not whitelisted (column, operator, direction) is a 422 with field errors,
 * so a probe like sort=name;drop table is refused, never interpolated.
 *
 * Response: {data: [...], meta: {page, per_page, total, last_page}}.
 *
 * @template TModel of Model
 */
final class AdminQuery
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    public const MAX_SEARCH = 100;

    public const MAX_LIST_VALUES = 100;

    public const MAX_VALUE_LENGTH = 255;

    public const OPERATORS = ['eq', 'like', 'in', 'between', 'null'];

    private const COLUMN_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    /** @var list<string> */
    private array $sortable = [];

    private ?string $defaultSort = null;

    private string $defaultDirection = 'asc';

    /** @var list<string> */
    private array $searchable = [];

    /** @var array<string, list<string>> filter key => allowed operators */
    private array $filters = [];

    /** @var array<string, string> filter key => column */
    private array $filterColumns = [];

    /** @var array<string, Closure(Builder<TModel>, string): void> */
    private array $customFilters = [];

    /**
     * @param  Builder<TModel>  $query
     */
    private function __construct(private readonly Builder $query, private readonly Request $request) {}

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return self<T>
     */
    public static function for(Builder $query, Request $request): self
    {
        return new self($query, $request);
    }

    /**
     * Columns the client may sort by, and the default sort.
     *
     * @param  list<string>  $columns
     * @return $this
     */
    public function sortable(array $columns, string $default, string $direction = 'asc'): self
    {
        foreach ($columns as $column) {
            self::assertColumn($column);
        }
        if (! in_array($default, $columns, true)) {
            throw new InvalidArgumentException("Default sort {$default} must be sortable.");
        }

        $this->sortable = $columns;
        $this->defaultSort = $default;
        $this->defaultDirection = self::direction($direction) ?? 'asc';

        return $this;
    }

    /**
     * Columns the free-text search looks in (OR'd together).
     *
     * @param  list<string>  $columns
     * @return $this
     */
    public function searchable(array $columns): self
    {
        foreach ($columns as $column) {
            self::assertColumn($column);
        }
        $this->searchable = $columns;

        return $this;
    }

    /**
     * Column filters: [key => [operators]] or [key => ['column' => 'table.col', 'operators' => [...]]].
     *
     * @param  array<string, list<string>|array{column: string, operators: list<string>}>  $filters
     * @return $this
     */
    public function filterable(array $filters): self
    {
        foreach ($filters as $key => $spec) {
            $column = isset($spec['column']) ? (string) $spec['column'] : $key;
            /** @var list<string> $operators */
            $operators = isset($spec['operators']) ? $spec['operators'] : array_values($spec);
            self::assertColumn($key);
            self::assertColumn($column);
            foreach ($operators as $operator) {
                if (! in_array($operator, self::OPERATORS, true)) {
                    throw new InvalidArgumentException("Unknown filter operator {$operator}.");
                }
            }
            $this->filters[$key] = $operators;
            $this->filterColumns[$key] = $column;
        }

        return $this;
    }

    /**
     * A filter with its own query logic (eq only), e.g. a relation: filter[show_id]=5.
     * The callback receives the raw string value and must bind it, never interpolate it.
     *
     * @param  Closure(Builder<TModel>, string): void  $apply
     * @return $this
     */
    public function filterUsing(string $key, Closure $apply): self
    {
        self::assertColumn($key);
        $this->customFilters[$key] = $apply;

        return $this;
    }

    /**
     * Applies search, filters and sort, paginates, and maps rows through $resource.
     *
     * @param  class-string<JsonResource>  $resource
     */
    public function respond(string $resource): JsonResponse
    {
        return response()->json($this->paginate($resource));
    }

    /**
     * @param  class-string<JsonResource>  $resource
     * @return array{data: array<int, mixed>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function paginate(string $resource): array
    {
        $errors = [];
        $page = $this->intParam('page', 1, 1, PHP_INT_MAX, $errors);
        $perPage = $this->intParam('per_page', self::DEFAULT_PER_PAGE, 1, self::MAX_PER_PAGE, $errors);

        $this->applySearch($errors);
        $this->applyFilters($errors);
        $this->applySort($errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $paginator = $this->query->paginate($perPage, ['*'], 'page', $page);

        /** @var array<int, mixed> $data */
        $data = $resource::collection($paginator->items())->resolve($this->request);

        return [
            'data' => $data,
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => max(1, $paginator->lastPage()),
            ],
        ];
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function intParam(string $name, int $default, int $min, int $max, array &$errors): int
    {
        $raw = $this->request->query($name);
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (! is_string($raw) || preg_match('/^\d{1,9}$/', $raw) !== 1) {
            $errors[$name][] = "The {$name} must be a whole number.";

            return $default;
        }
        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            $errors[$name][] = "The {$name} must be between {$min} and {$max}.";

            return $default;
        }

        return $value;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function applySearch(array &$errors): void
    {
        $search = $this->request->query('search');
        if ($search === null || $search === '') {
            return;
        }
        if (! is_string($search) || mb_strlen($search) > self::MAX_SEARCH) {
            $errors['search'][] = 'The search may not be longer than '.self::MAX_SEARCH.' characters.';

            return;
        }
        $term = trim($search);
        if ($term === '' || $this->searchable === []) {
            return;
        }

        $pattern = '%'.self::escapeLike($term).'%';
        $this->query->where(function (Builder $inner) use ($pattern): void {
            foreach ($this->searchable as $column) {
                $inner->orWhere($column, 'like', $pattern);
            }
        });
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function applyFilters(array &$errors): void
    {
        $filters = $this->request->query('filter');
        if ($filters === null || $filters === '') {
            return;
        }
        if (! is_array($filters)) {
            $errors['filter'][] = 'The filter must be a list of column filters.';

            return;
        }

        foreach ($filters as $key => $spec) {
            $key = (string) $key;
            $field = 'filter.'.$key;

            if (isset($this->customFilters[$key])) {
                $value = is_array($spec) ? ($spec['eq'] ?? null) : $spec;
                if (! self::validScalar($value)) {
                    $errors[$field][] = 'The filter value is invalid.';

                    continue;
                }
                ($this->customFilters[$key])($this->query, (string) $value);

                continue;
            }

            if (! isset($this->filters[$key])) {
                $errors[$field][] = 'Filtering by this field is not allowed.';

                continue;
            }

            $pairs = is_array($spec) ? $spec : ['eq' => $spec];
            foreach ($pairs as $operator => $value) {
                $operator = (string) $operator;
                if (! in_array($operator, $this->filters[$key], true)) {
                    $errors[$field][] = 'This filter operator is not allowed.';

                    continue;
                }
                $problem = $this->applyFilter($this->filterColumns[$key], $operator, $value);
                if ($problem !== null) {
                    $errors[$field][] = $problem;
                }
            }
        }
    }

    private function applyFilter(string $column, string $operator, mixed $value): ?string
    {
        switch ($operator) {
            case 'eq':
                if (! self::validScalar($value)) {
                    return 'The filter value is invalid.';
                }
                $this->query->where($column, '=', (string) $value);

                return null;

            case 'like':
                if (! self::validScalar($value) || (string) $value === '') {
                    return 'The filter value is invalid.';
                }
                $this->query->where($column, 'like', '%'.self::escapeLike((string) $value).'%');

                return null;

            case 'in':
                $values = self::listValue($value);
                if ($values === null || $values === [] || count($values) > self::MAX_LIST_VALUES) {
                    return 'The filter needs 1 to '.self::MAX_LIST_VALUES.' values.';
                }
                $this->query->whereIn($column, $values);

                return null;

            case 'between':
                $values = self::listValue($value);
                if ($values === null || count($values) !== 2) {
                    return 'The filter needs exactly two values.';
                }
                $this->query->whereBetween($column, [$values[0], $values[1]]);

                return null;

            default: // null
                if (! in_array($value, ['1', '0', 'true', 'false'], true)) {
                    return 'The filter value must be 1 or 0.';
                }
                in_array($value, ['1', 'true'], true) ? $this->query->whereNull($column) : $this->query->whereNotNull($column);

                return null;
        }
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function applySort(array &$errors): void
    {
        if ($this->defaultSort === null) {
            return;
        }

        $sort = $this->request->query('sort');
        $direction = $this->request->query('direction');

        $column = $this->defaultSort;
        if ($sort !== null && $sort !== '') {
            if (! is_string($sort) || ! in_array($sort, $this->sortable, true)) {
                $errors['sort'][] = 'Sorting by this field is not allowed.';
            } else {
                $column = $sort;
            }
        }

        $dir = $this->defaultDirection;
        if ($direction !== null && $direction !== '') {
            $parsed = is_string($direction) ? self::direction($direction) : null;
            if ($parsed === null) {
                $errors['direction'][] = 'The direction must be asc or desc.';
            } else {
                $dir = $parsed;
            }
        }

        $this->query->orderBy($column, $dir);
        // A stable tie-break so pages never repeat or skip rows.
        $key = $this->query->getModel()->getQualifiedKeyName();
        if ($column !== $this->query->getModel()->getKeyName() && $column !== $key) {
            $this->query->orderBy($key, $dir);
        }
    }

    private static function direction(string $direction): ?string
    {
        $lower = strtolower($direction);

        return in_array($lower, ['asc', 'desc'], true) ? $lower : null;
    }

    private static function validScalar(mixed $value): bool
    {
        return (is_string($value) || is_int($value)) && mb_strlen((string) $value) <= self::MAX_VALUE_LENGTH;
    }

    /**
     * @return list<string>|null
     */
    private static function listValue(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }
        if (! is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $item) {
            if (! self::validScalar($item)) {
                return null;
            }
            $out[] = (string) $item;
        }

        return $out;
    }

    /** Escapes LIKE wildcards so user text matches literally (MySQL/MariaDB default escape is backslash). */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private static function assertColumn(string $column): void
    {
        if (preg_match(self::COLUMN_PATTERN, $column) !== 1) {
            throw new InvalidArgumentException("Invalid column name {$column}.");
        }
    }
}
