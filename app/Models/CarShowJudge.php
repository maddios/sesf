<?php

namespace App\Models;

use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\CarShowJudgeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A judge assigned to a car show (legacy table car_show_judge). Ports app/models/CarShowJudge.php.
 *
 * The primary key is the pair (user_id, show_id). Laravel cannot key on two columns, so
 * setKeysForSaveQuery() adds both columns. Without that, delete() would remove every assignment
 * for the user.
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

    protected $guarded = ['id'];

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
     * Save and delete must match both key columns.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query
            ->where('car_show_judge.user_id', '=', $this->getAttribute('user_id'))
            ->where('car_show_judge.show_id', '=', $this->getAttribute('show_id'));
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
