<?php

namespace App\Models;

use App\Enums\SponsorType;
use App\Enums\UserType;
use App\Models\Casts\Money2;
use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\CarShowFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Legacy car show (table car_show). Ports app/models/CarShow.php.
 *
 * Time-dependent accessors read now() so tests can use travelTo().
 * Zero and null dates count as "not set": see regOpen and ended for the legacy edge cases.
 * Copying bodies and judging setup from another show is done by App\Services\CloneShowConfiguration.
 *
 * @property int $id
 * @property string $short_name
 * @property string $name
 * @property string|null $description
 * @property string|null $schedule
 * @property bool $is_schedule_pdf
 * @property string|null $short_description
 * @property string|null $price_car
 * @property string|null $price_adult
 * @property string|null $price_child
 * @property CarbonInterface|null $date_start
 * @property CarbonInterface|null $date_end
 * @property CarbonInterface|null $date_active
 * @property CarbonInterface|null $date_registration_deactivation
 * @property bool $is_active
 * @property bool|null $is_judging_released
 * @property string|null $waiver_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property bool $has_image
 * @property int|null $primary_sponsor_id
 * @property array<int|string, mixed>|null $shirt_stock_json
 * @property-read string $imageURL
 * @property-read string $profileURL
 * @property-read string $shortDescription
 * @property-read bool $regOpen
 * @property-read bool $ended
 * @property-read string $URLName
 */
class CarShow extends Model
{
    /** @use HasFactory<CarShowFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'car_show';

    public $timestamps = true;

    protected $guarded = ['id'];

    protected $appends = ['imageURL', 'profileURL', 'shortDescription', 'regOpen', 'ended'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_car' => Money2::class,
            'price_adult' => Money2::class,
            'price_child' => Money2::class,
            'date_start' => 'date',
            'date_end' => 'date',
            'date_active' => 'datetime',
            'date_registration_deactivation' => 'datetime',
            'is_schedule_pdf' => 'boolean',
            'is_active' => 'boolean',
            'is_judging_released' => 'boolean',
            'has_image' => 'boolean',
            'shirt_stock_json' => 'array',
        ];
    }

    /**
     * Legacy: strtotime(date_end) < time(). A missing or zero end date counts as ended.
     */
    public function getEndedAttribute(): bool
    {
        return $this->date_end === null || $this->date_end->lessThan(now());
    }

    /**
     * Legacy: date_active < now < date_registration_deactivation.
     * A missing or zero activation date counts as already started (legacy strtotime(null) is 0).
     * A missing or zero deactivation date counts as closed (legacy strtotime(null) is 0, not in the future).
     */
    public function getRegOpenAttribute(): bool
    {
        $now = now();
        $started = $this->date_active === null || $this->date_active->lessThan($now);
        $notDeactivated = $this->date_registration_deactivation !== null
            && $this->date_registration_deactivation->greaterThan($now);

        return $started && $notDeactivated;
    }

    public function getImageURLAttribute(): string
    {
        return $this->has_image
            ? config('sesf.show_image_url').$this->id.'.jpg'
            : config('sesf.image_url').'generic-show.jpg';
    }

    /**
     * Legacy: the stored short_description, or the first 350 characters of description
     * followed by '...'. The raw column is read directly, because the accessor name
     * would otherwise be resolved for the column itself.
     */
    public function getShortDescriptionAttribute(): string
    {
        return $this->attributes['short_description']
            ?: substr((string) $this->description, 0, 350).'...';
    }

    public function getProfileURLAttribute(): string
    {
        return '#/'.$this->URLName.'/e'.$this->id;
    }

    /**
     * Legacy: name with every non-alphanumeric character removed.
     */
    public function getURLNameAttribute(): string
    {
        return preg_replace('/[^a-z0-9]/i', '', (string) $this->name) ?? '';
    }

    /**
     * Legacy hasUsers: shows that have at least one registration.
     */
    /**
     * @param  Builder<CarShow>  $query
     * @return Builder<CarShow>
     */
    public function scopeHasUsers(Builder $query): Builder
    {
        return $query
            ->join('show_registration', 'show_registration.show_id', '=', 'car_show.id')
            ->select('car_show.*')
            ->groupBy('car_show.id');
    }

    /**
     * Legacy calculateNumCars: adds num_cars, the number of cars registered for each show.
     */
    /**
     * @param  Builder<CarShow>  $query
     * @return Builder<CarShow>
     */
    public function scopeCalculateNumCars(Builder $query): Builder
    {
        return $query
            ->join('show_registration', 'show_registration.show_id', '=', 'car_show.id')
            ->join('show_registration_car', 'show_registration_car.registration_id', '=', 'show_registration.id')
            ->join('car', 'car.id', '=', 'show_registration_car.car_id')
            ->select('car_show.*')
            ->selectRaw('COUNT(car.id) AS num_cars')
            ->groupBy('car_show.id');
    }

    /**
     * Legacy isJudgeOrAdmin. Admins see every show. Everyone else sees only shows where
     * they are listed in car_show_judge. The legacy code read the logged-in user itself;
     * here the caller passes the id and type.
     */
    /**
     * @param  Builder<CarShow>  $query
     * @return Builder<CarShow>
     */
    public function scopeVisibleTo(Builder $query, int $userId, UserType $type): Builder
    {
        if ($type->isAdmin()) {
            return $query;
        }

        return $query
            ->join('car_show_judge', function ($join) use ($userId): void {
                $join->on('car_show_judge.show_id', '=', 'car_show.id')
                    ->where('car_show_judge.user_id', '=', $userId);
            })
            ->select('car_show.*')
            ->groupBy('car_show.id');
    }

    /**
     * @return HasMany<ShowRegistration, $this>
     */
    public function registrations(): HasMany // @phpstan-ignore-line later-wave class
    {
        return $this->hasMany(ShowRegistration::class, 'show_id'); // @phpstan-ignore-line later-wave class
    }

    /**
     * @return HasMany<CarShowJudge, $this>
     */
    public function carShowJudges(): HasMany // @phpstan-ignore-line later-wave class
    {
        return $this->hasMany(CarShowJudge::class, 'show_id'); // @phpstan-ignore-line later-wave class
    }

    /**
     * @return BelongsToMany<CarBody, $this>
     */
    public function carBodies(): BelongsToMany
    {
        return $this->belongsToMany(CarBody::class, 'car_body_show', 'show_id', 'body_id');
    }

    /**
     * Legacy judging setup: judging_show_body rows for this show, with the judging type
     * on the pivot.
     *
     * @return BelongsToMany<CarBody, $this>
     */
    public function judgingBodies(): BelongsToMany
    {
        return $this->belongsToMany(CarBody::class, 'judging_show_body', 'car_show_id', 'car_body_id')
            ->withPivot('judging_type_id');
    }

    /**
     * @return BelongsToMany<Sponsor, $this>
     */
    public function sponsors(): BelongsToMany
    {
        return $this->belongsToMany(Sponsor::class, 'show_sponsor', 'show_id', 'sponsor_id')
            ->withPivot('type_id');
    }

    /**
     * Legacy primary_sponsors: sponsors whose show_sponsor type is M.
     *
     * @return BelongsToMany<Sponsor, $this>
     */
    public function primary_sponsors(): BelongsToMany
    {
        return $this->belongsToMany(Sponsor::class, 'show_sponsor', 'show_id', 'sponsor_id')
            ->withPivot('type_id')
            ->wherePivot('type_id', SponsorType::M->value);
    }

    /**
     * @return BelongsTo<Sponsor, $this>
     */
    public function primary_sponsor(): BelongsTo
    {
        return $this->belongsTo(Sponsor::class, 'primary_sponsor_id');
    }

    /**
     * @return HasMany<CarShowLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(CarShowLocation::class, 'show_id');
    }

    /**
     * @return HasOne<CarShowLocation, $this>
     */
    public function primaryLocation(): HasOne
    {
        return $this->hasOne(CarShowLocation::class, 'show_id')->where('is_primary', true);
    }

    /**
     * @return HasMany<ShowExtra, $this>
     */
    public function showExtras(): HasMany
    {
        return $this->hasMany(ShowExtra::class, 'show_id');
    }

    /**
     * @return HasMany<Gallery, $this>
     */
    public function galleries(): HasMany
    {
        return $this->hasMany(Gallery::class, 'show_id');
    }
}
