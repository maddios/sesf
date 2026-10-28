<?php

namespace App\Services\Admin\Reports;

use App\Models\User;
use App\Support\Admin\AdminQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Members report (legacy MemberList). Filters are AdminQuery filters: show_id (registered for the
 * event), is_paid (a registration with that paid flag; scoped to show_id when given, as legacy did),
 * and the three notification preferences.
 */
final class MemberReport
{
    /**
     * @return AdminQuery<User>
     */
    public static function query(Request $request): AdminQuery
    {
        return self::configure(AdminQuery::for(User::query(), $request), $request);
    }

    /**
     * @param  AdminQuery<User>  $query
     * @return AdminQuery<User>
     */
    private static function configure(AdminQuery $query, Request $request): AdminQuery
    {
        $filter = $request->query('filter');
        $showId = is_array($filter) && isset($filter['show_id']) ? self::flag($filter['show_id'], 'show_id') : null;

        return $query
            ->sortable(['last', 'first', 'email', 'id'], default: 'last')
            ->searchable(['first', 'last', 'email'])
            ->filterUsing('show_id', function (Builder $users, string $value): void {
                $users->whereHas('registrations', fn (Builder $r) => $r->where('show_id', self::flag($value, 'show_id')));
            })
            ->filterUsing('is_paid', function (Builder $users, string $value) use ($showId): void {
                $paid = (bool) self::flag($value, 'is_paid');
                $users->whereHas('registrations', function (Builder $r) use ($paid, $showId): void {
                    $r->where('is_paid', $paid);
                    if ($showId !== null) {
                        $r->where('show_id', $showId);
                    }
                });
            })
            ->filterUsing('allow_future_show_notifications', fn (Builder $users, string $value) => self::preference($users, 'allow_future_show_notifications', $value))
            ->filterUsing('allow_system_email', fn (Builder $users, string $value) => self::preference($users, 'allow_system_email', $value))
            ->filterUsing('allow_private_message', fn (Builder $users, string $value) => self::preference($users, 'allow_private_message', $value));
    }

    /** @param Builder<User> $users */
    private static function preference(Builder $users, string $column, string $value): void
    {
        $users->whereHas('preferences', fn (Builder $p) => $p->where($column, (bool) self::flag($value, $column)));
    }

    /** A show id or a 0/1 flag as an int; anything else is a 422 on that filter. */
    private static function flag(mixed $value, string $key): int
    {
        $valid = is_string($value) && ($key === 'show_id'
            ? ctype_digit($value) && (int) $value > 0
            : in_array($value, ['0', '1'], true));
        if (! $valid) {
            throw ValidationException::withMessages(["filter.{$key}" => ["The {$key} filter is not valid."]]);
        }

        return (int) $value;
    }
}
