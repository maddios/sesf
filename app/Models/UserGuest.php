<?php

namespace App\Models;

use App\Enums\UserGuestType;
use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\UserGuestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guest attached to a registration (legacy table user_guest). Ports app/models/UserGuest.php.
 *
 * signature_image is a legacy blob and is hidden from JSON. It is still selected by SELECT *,
 * so list queries that do not need it should pick columns explicitly.
 *
 * JSON contract: fullName is the key the Vue SPA reads.
 *
 * @property int $id
 * @property int $user_id
 * @property string $first
 * @property string $last
 * @property string|null $email
 * @property int $show_id
 * @property int|null $waiver_id
 * @property UserGuestType $type_id
 * @property bool $has_signed
 * @property string|null $signature_image
 * @property int $registration_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read string $fullName
 */
class UserGuest extends Model
{
    /** @use HasFactory<UserGuestFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'user_guest';

    public $timestamps = true;

    protected $guarded = ['id', 'has_signed', 'signature_image', 'waiver_id'];

    protected $hidden = ['signature_image'];

    protected $appends = ['fullName'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'show_id' => 'integer',
            'waiver_id' => 'integer',
            'registration_id' => 'integer',
            'type_id' => UserGuestType::class,
            'has_signed' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Legacy fullName: "first last".
     */
    public function getFullNameAttribute(): string
    {
        return $this->first.' '.$this->last;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<ShowRegistration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(ShowRegistration::class, 'registration_id');
    }

    /**
     * @return BelongsTo<CarShow, $this>
     */
    public function carShow(): BelongsTo
    {
        return $this->belongsTo(CarShow::class, 'show_id');
    }

    /**
     * @return BelongsTo<Waiver, $this>
     */
    public function waiver(): BelongsTo
    {
        return $this->belongsTo(Waiver::class, 'waiver_id');
    }
}
