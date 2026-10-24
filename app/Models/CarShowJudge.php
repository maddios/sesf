<?php

namespace App\Models;

use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\CarShowJudgeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A judge assigned to a car show (legacy table car_show_judge). Ports app/models/CarShowJudge.php.
 *
 * The primary key is the pair (user_id, show_id). Laravel cannot key on two columns, so:
 *  - save/delete/refresh/fresh match both columns, using the values last read from or written to
 *    the database (getOriginal). Changing show_id or user_id and calling save() moves that one row.
 *  - getKey() returns the composite "user_id:show_id" string, so is(), unique() and other
 *    collection lookups tell two assignments of one judge apart.
 *  - find(), findMany(), findOrFail(), whereKey(), whereKeyNot() and destroy() would match on
 *    user_id alone, so they throw LogicException. Use findByKeys() or scopeAssignedTo().
 *  - $primaryKey stays 'user_id' only because Laravel needs a single key name internally.
 *
 * Only the key columns are mass assignable: created_at is set by the database default.
 *
 * The table has created_at only, so $timestamps is false. created_at is set by the database default.
 *
 * @property int $user_id
 * @property int $show_id
 * @property CarbonInterface|null $created_at
 */
class CarShowJudge extends Model
{
    /** @use HasFactory<CarShowJudgeFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'car_show_judge';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'show_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Restricts the query to the one (user, show) assignment. Legacy static
     * userAssignedToShow(): use CarShowJudge::query()->assignedTo($userId, $showId)->exists().
     *
     * @param  Builder<CarShowJudge>  $query
     * @return Builder<CarShowJudge>
     */
    public function scopeAssignedTo(Builder $query, int $userId, int $showId): Builder
    {
        return $query->where('car_show_judge.user_id', $userId)
            ->where('car_show_judge.show_id', $showId);
    }

    /**
     * The one assignment for this (user, show) pair, or null.
     */
    public static function findByKeys(int $userId, int $showId): ?self
    {
        return static::query()->assignedTo($userId, $showId)->first();
    }

    /**
     * The composite key as "user_id:show_id", or null when either column is unset.
     */
    public function getKey(): ?string
    {
        $userId = $this->getAttribute('user_id');
        $showId = $this->getAttribute('show_id');

        if ($userId === null || $showId === null) {
            return null;
        }

        return $userId.':'.$showId;
    }

    /**
     * destroy() would select by user_id alone, so it is refused.
     *
     * @param  mixed  $ids
     *
     * @throws LogicException always.
     */
    public static function destroy($ids): int
    {
        throw self::compositeKeyError('destroy');
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return Builder<CarShowJudge>
     */
    public function newEloquentBuilder($query): Builder
    {
        // Anonymous class: the guard belongs to this model only (no separate builder file).
        return new
            /** @extends Builder<CarShowJudge> */
            class($query) extends Builder
            {
                public function find($id, $columns = ['*'])
                {
                    throw CarShowJudge::compositeKeyError('find');
                }

                public function findMany($ids, $columns = ['*'])
                {
                    throw CarShowJudge::compositeKeyError('findMany');
                }

                public function whereKey($id)
                {
                    throw CarShowJudge::compositeKeyError('whereKey');
                }

                public function whereKeyNot($id)
                {
                    throw CarShowJudge::compositeKeyError('whereKeyNot');
                }
            };
    }

    /**
     * Save, update and delete match both key columns, using the values the row has in the
     * database (getOriginal), so changing a key column and saving updates the existing row.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query
            ->where('car_show_judge.user_id', '=', $this->getOriginal('user_id', $this->getAttribute('user_id')))
            ->where('car_show_judge.show_id', '=', $this->getOriginal('show_id', $this->getAttribute('show_id')));
    }

    /**
     * refresh() and fresh() read the row through this query.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSelectQuery($query)
    {
        return $this->setKeysForSaveQuery($query);
    }

    /** @internal Used by the builder returned from newEloquentBuilder(). */
    public static function compositeKeyError(string $method): LogicException
    {
        return new LogicException(sprintf(
            'CarShowJudge has a composite key (user_id, show_id); %s() would match on user_id only. '
            .'Use CarShowJudge::findByKeys($userId, $showId) or the assignedTo() scope.',
            $method,
        ));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function judge(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<CarShow, $this>
     */
    public function carShow(): BelongsTo
    {
        return $this->belongsTo(CarShow::class, 'show_id');
    }
}
