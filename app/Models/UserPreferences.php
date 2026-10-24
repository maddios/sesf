<?php

namespace App\Models;

use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\UserPreferencesFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-member notification and display preferences (legacy `user_preferences`).
 * One row per user, created automatically when the user is created (UserObserver).
 *
 * @property int $id
 * @property int $user_id
 * @property bool $show_car_on_attendees_page
 * @property bool $allow_future_show_notifications
 * @property bool $allow_system_email
 * @property bool $show_login_welcome
 * @property bool $allow_private_message
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class UserPreferences extends Model
{
    /** @use HasFactory<UserPreferencesFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'user_preferences';

    public $timestamps = true;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'show_car_on_attendees_page' => 'boolean',
            'allow_future_show_notifications' => 'boolean',
            'allow_system_email' => 'boolean',
            'show_login_welcome' => 'boolean',
            'allow_private_message' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
