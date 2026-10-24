<?php

namespace App\Models;

use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\LoginEmailSigninFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One emailed sign-in request (legacy `login_email_signin`). The table has only created_at,
 * so timestamps are off. Token and code are stored as hashes; generating them is not this
 * model's job (auth slice). token_hash, code_hash and request_ip are hidden.
 *
 * Mass assignment: used_at is guarded, so only the code that consumes the request sets it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string $code_hash
 * @property string|null $redirect_path
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $used_at
 * @property CarbonInterface|null $created_at
 * @property string|null $request_ip
 */
class LoginEmailSignin extends Model
{
    /** @use HasFactory<LoginEmailSigninFactory> */
    use HasFactory, HasLegacyDates;

    /**
     * Daily cap so email sign-in cannot use up the monthly mail quota. Once it is reached, the
     * UI hides the "sign in with an emailed code" link until the day rolls over (app timezone).
     */
    public const DAILY_QUOTA = 100;

    protected $table = 'login_email_signin';

    public $timestamps = false;

    protected $guarded = ['id', 'used_at'];

    /**
     * Secrets and the requester's IP never appear in toArray() or toJson(). token_hash and code_hash
     * are the stored sign-in secrets, and request_ip is personal data.
     */
    protected $hidden = ['token_hash', 'code_hash', 'request_ip'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Rows that have not been used yet.
     *
     * @param  Builder<LoginEmailSignin>  $query
     * @return Builder<LoginEmailSignin>
     */
    public function scopeUnused(Builder $query): Builder
    {
        return $query->whereNull('used_at');
    }

    /**
     * Rows whose expiry time is still in the future.
     *
     * @param  Builder<LoginEmailSignin>  $query
     * @return Builder<LoginEmailSignin>
     */
    public function scopeUnexpired(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Rows created since the start of today (app timezone).
     *
     * @param  Builder<LoginEmailSignin>  $query
     * @return Builder<LoginEmailSignin>
     */
    public function scopeCreatedToday(Builder $query): Builder
    {
        return $query->where('created_at', '>=', Carbon::today());
    }

    /**
     * How many sign-in emails were requested today (legacy sentToday()).
     */
    public static function sentToday(): int
    {
        return self::query()->createdToday()->count();
    }

    /**
     * True while today's sign-in emails are below DAILY_QUOTA (legacy isAvailable()).
     */
    public static function isAvailable(): bool
    {
        return self::sentToday() < self::DAILY_QUOTA;
    }
}
