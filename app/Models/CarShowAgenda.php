<?php

namespace App\Models;

use App\Enums\AgendaType;
use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\CarShowAgendaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy show agenda item (table show_agenda). Ports app/models/CarShowAgenda.php.
 * The legacy type relation (show_agenda_type) has no model yet, so type_id is cast to AgendaType.
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $text
 * @property bool $is_visible
 * @property bool $is_pdf
 * @property int $sort_order
 * @property int $show_id
 * @property AgendaType $type_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class CarShowAgenda extends Model
{
    /** @use HasFactory<CarShowAgendaFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'show_agenda';

    public $timestamps = true;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type_id' => AgendaType::class,
            'is_visible' => 'boolean',
            'is_pdf' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<CarShowAgenda>  $query
     * @return Builder<CarShowAgenda>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    /**
     * @param  Builder<CarShowAgenda>  $query
     * @return Builder<CarShowAgenda>
     */
    public function scopeForShow(Builder $query, int $showId): Builder
    {
        return $query->where('show_id', $showId);
    }

    /**
     * @param  Builder<CarShowAgenda>  $query
     * @return Builder<CarShowAgenda>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<CarShow, $this>
     */
    public function show(): BelongsTo
    {
        return $this->belongsTo(CarShow::class, 'show_id');
    }
}
