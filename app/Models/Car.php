<?php

namespace App\Models;

use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\CarFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\File;

/**
 * A car owned by a member (legacy table car).
 *
 * JSON contract: the accessors below are the keys the Vue SPA reads. Keep the camelCase names.
 * user_id is null for a sold car. The owner row is kept, so the car stays with its show history.
 *
 * Images: the file path is config('sesf.car_image_root'), and the URL is config('sesf.car_image_url').
 *
 * @property int $id
 * @property int|null $user_id
 * @property bool $has_image
 * @property bool $is_confirmed
 * @property string|null $model
 * @property string|null $year
 * @property int $body_id
 * @property int $type_id
 * @property string|null $nickname
 * @property string|null $VIN
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read string $imageURL
 * @property-read string $profileURL
 * @property-read string $name
 * @property-read string $fullName
 * @property-read string|null $editableModel
 */
class Car extends Model
{
    /** @use HasFactory<CarFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'car';

    public $timestamps = true;

    protected $guarded = ['id'];

    protected $appends = ['imageURL', 'profileURL', 'name', 'fullName', 'editableModel'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'has_image' => 'boolean',
            'is_confirmed' => 'boolean',
            'body_id' => 'integer',
            'type_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Profile image URL (legacy getImageURL('profile')).
     */
    public function getImageURLAttribute(): string
    {
        return $this->imageUrlFor('profile');
    }

    /**
     * Legacy getImageURL($image): the car's image with a filemtime cache-bust (only when the
     * file exists), or the generic car image when the car has no image.
     */
    public function imageUrlFor(string $image): string
    {
        if (! $this->has_image) {
            return config('sesf.image_url').'car-generic.jpg';
        }

        $file = $this->getImagePathAttribute().$image.'.jpg';
        $bust = File::isFile($file) ? '?'.File::lastModified($file) : '';

        return config('sesf.car_image_url').$this->id.'/'.$image.'.jpg'.$bust;
    }

    /**
     * Filesystem directory holding this car's images.
     */
    public function getImagePathAttribute(): string
    {
        return config('sesf.car_image_root').$this->id.'/';
    }

    /**
     * Legacy hash URL for the car page, e.g. "#/car/12".
     */
    public function getProfileURLAttribute(): string
    {
        return '#/car/'.$this->id;
    }

    /**
     * Legacy "year model", e.g. "1969 Camaro". Missing parts become empty strings.
     */
    public function getNameAttribute(): string
    {
        return ((string) $this->year).' '.((string) $this->model);
    }

    /**
     * Legacy fullName: the same value as name.
     */
    public function getFullNameAttribute(): string
    {
        return $this->name;
    }

    /**
     * Legacy editableModel: the model text, used by the edit form.
     */
    public function getEditableModelAttribute(): ?string
    {
        return $this->model;
    }

    public function setEditableModelAttribute(?string $value): void
    {
        $this->attributes['model'] = $value;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<CarBody, $this>
     */
    public function body(): BelongsTo
    {
        return $this->belongsTo(CarBody::class, 'body_id');
    }

    /**
     * @return BelongsTo<CarType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(CarType::class, 'type_id');
    }

    /**
     * Show registrations this car is entered in (show_registration_car).
     *
     * @return BelongsToMany<ShowRegistration, $this>
     */
    public function registrations(): BelongsToMany
    {
        return $this->belongsToMany(ShowRegistration::class, 'show_registration_car', 'car_id', 'registration_id');
    }

    /**
     * Cars registered for a show (legacy whereShowId). Uses a subquery, so a car appears once.
     *
     * @param  Builder<Car>  $query
     * @return Builder<Car>
     */
    public function scopeWhereShowId(Builder $query, int $showId): Builder
    {
        return $query->whereIn($query->qualifyColumn('id'), function ($sub) use ($showId): void {
            $sub->from('show_registration_car')
                ->join('show_registration', 'show_registration.id', '=', 'show_registration_car.registration_id')
                ->where('show_registration.show_id', '=', $showId)
                ->select('show_registration_car.car_id');
        });
    }

    /**
     * Cars with a given body style (legacy whereBodyId).
     *
     * @param  Builder<Car>  $query
     * @return Builder<Car>
     */
    public function scopeWhereBodyId(Builder $query, int $bodyId): Builder
    {
        return $query->where($query->qualifyColumn('body_id'), '=', $bodyId);
    }

    /**
     * Cars whose body is allowed at a show (legacy restrictCarsByBodyByShow). Adds body_name.
     * Legacy mutated $this->select, which is broken; the select list is built here instead.
     *
     * @param  Builder<Car>  $query
     * @return Builder<Car>
     */
    public function scopeRestrictCarsByBodyByShow(Builder $query, int $showId): Builder
    {
        return $query
            ->join('car_body', 'car_body.id', '=', 'car.body_id')
            ->join('car_body_show', 'car_body_show.body_id', '=', 'car.body_id')
            ->where('car_body_show.show_id', '=', $showId)
            ->select('car.*', 'car_body.name as body_name');
    }
}
