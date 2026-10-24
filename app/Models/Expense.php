<?php

namespace App\Models;

use App\Models\Casts\Money2;
use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy show expense (table expense). Ports app/models/Expense.php.
 *
 * Sign convention: the legacy model did not define one, so amount is stored exactly as given
 * (no sign is enforced or inverted). show_id is nullable, so an expense may have no show.
 *
 * @property int $id
 * @property string $description
 * @property string|null $amount
 * @property int|null $show_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'expense';

    public $timestamps = true;

    protected $guarded = ['id', 'amount'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => Money2::class.':999999.99',
        ];
    }

    /**
     * @return BelongsTo<CarShow, $this>
     */
    public function show(): BelongsTo
    {
        return $this->belongsTo(CarShow::class, 'show_id');
    }
}
