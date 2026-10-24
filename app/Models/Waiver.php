<?php

namespace App\Models;

use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\WaiverFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Liability waiver for a registrant, guest or ad hoc visitor (legacy table waiver). Ports
 * app/models/Waiver.php (namespace Model\Waiver).
 *
 * Lifecycle: a waiver is created pending with a tracking_id, which is the secret in the public
 * sign URL (signUrl()). Signing sets waiver_id (public, 16 hex) and has_signed. Until then
 * waiver_id is null.
 *
 * waiver_data holds the signature (base64) and geolocation, so it is cast to an array and hidden
 * from JSON.
 *
 * Owners: a waiver belongs to a registration (show_registration_id), a guest (guest_id) or an ad
 * hoc show (adhoc_show_id). The guest pair is circular: user_guest.waiver_id points back here.
 *
 * @property int $id
 * @property string $tracking_id
 * @property string|null $waiver_id
 * @property int|null $show_registration_id
 * @property int|null $guest_id
 * @property int|null $adhoc_show_id
 * @property string|null $request_waiver_url
 * @property bool $has_signed
 * @property array<int|string, mixed>|null $waiver_data
 * @property CarbonInterface|null $signed_at
 * @property bool $has_pdf
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Waiver extends Model
{
    /** @use HasFactory<WaiverFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'waiver';

    public $timestamps = true;

    protected $guarded = ['id', 'tracking_id', 'waiver_id', 'has_signed', 'signed_at', 'waiver_data', 'has_pdf'];

    /**
     * waiver_data holds the signature. tracking_id and request_waiver_url are the capability token
     * of the public sign link, so they are hidden too. Flows that need them read them explicitly
     * (getAttribute() or signUrl()).
     */
    protected $hidden = ['waiver_data', 'request_waiver_url', 'tracking_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_registration_id' => 'integer',
            'guest_id' => 'integer',
            'adhoc_show_id' => 'integer',
            'has_signed' => 'boolean',
            'waiver_data' => 'array',
            'signed_at' => 'datetime',
            'has_pdf' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function isSigned(): bool
    {
        return $this->has_signed;
    }

    /**
     * Pending means the waiver has not been signed yet, so waiver_id is still unset.
     */
    public function isPending(): bool
    {
        return $this->waiver_id === null || $this->waiver_id === '';
    }

    public function isAdhoc(): bool
    {
        return (int) $this->adhoc_show_id > 0;
    }

    /**
     * The public URL the signer opens. Built from config('app.url'), so it does not depend on
     * the current request (it is also right in queue jobs and console commands).
     */
    public function signUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/waiver_sign/t/'.$this->tracking_id;
    }

    /**
     * Waivers that are not signed yet.
     *
     * @param  Builder<Waiver>  $query
     * @return Builder<Waiver>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner->whereNull('waiver_id')->orWhere('waiver_id', '');
        });
    }

    /**
     * Waivers that have been signed.
     *
     * @param  Builder<Waiver>  $query
     * @return Builder<Waiver>
     */
    public function scopeSigned(Builder $query): Builder
    {
        return $query->where('has_signed', true);
    }

    /**
     * Waivers that belong to one show: through a registration of the show, through a guest of
     * the show, or as an ad hoc waiver for it.
     *
     * @param  Builder<Waiver>  $query
     * @return Builder<Waiver>
     */
    public function scopeForShow(Builder $query, int $showId): Builder
    {
        return $query->where(function (Builder $inner) use ($showId): void {
            $inner->where('adhoc_show_id', $showId)
                ->orWhereIn('show_registration_id', ShowRegistration::query()->select('id')->where('show_id', $showId))
                ->orWhereIn('guest_id', UserGuest::query()->select('id')->where('show_id', $showId));
        });
    }

    /**
     * @return BelongsTo<ShowRegistration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(ShowRegistration::class, 'show_registration_id');
    }

    /**
     * @return BelongsTo<UserGuest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(UserGuest::class, 'guest_id');
    }

    /**
     * @return BelongsTo<CarShow, $this>
     */
    public function carShow(): BelongsTo
    {
        return $this->belongsTo(CarShow::class, 'adhoc_show_id');
    }
}
