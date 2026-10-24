<?php

namespace App\Models;

use App\Enums\UserType;
use App\Models\Concerns\HasLegacyDates;
use App\Observers\UserObserver;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

/**
 * A member of the site (legacy `user` table).
 *
 * JSON contract: the accessors below are the keys the Vue SPA reads. Their values are the
 * UNMASKED values. Legacy code showed only the state for guests (cityState) or the initials
 * for guests (fullName). Masking per viewer belongs in the API Resources, not here: the model
 * never reads Auth or the request.
 *
 * Password: legacy save() only changed the hash when a confirmed password was supplied. That
 * rule lives in setPasswordWithConfirmation(); the caller must still save(). Blank passwords are
 * never written (see UserObserver).
 *
 * Geocoding: saving an address does not call Google. UserObserver queues GeocodeUserAddress.
 *
 * Mass assignment: the guarded columns are the identity and privilege columns (id, is_admin,
 * type_id), the credentials (password, remember_token), the soft-delete marker and date_registered.
 * Write them with setters, forceFill() or a query-builder update, never with fill() or create().
 *
 * Hidden: password and remember_token. imagePath is a server filesystem path, so it is an accessor
 * for internal use only and is not in $appends.
 *
 * @property int $id
 * @property CarbonInterface|null $date_registered
 * @property string|null $first
 * @property string|null $last
 * @property string $email
 * @property string|null $phone
 * @property string|null $phone2
 * @property int $location_id
 * @property int $is_confirmed
 * @property string|null $remember_token
 * @property string|null $heard_from
 * @property string $password
 * @property bool $is_admin
 * @property CarbonInterface|null $date_updated
 * @property UserType $type_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property bool $has_image
 * @property string $address1
 * @property string|null $address2
 * @property string $city
 * @property string $state
 * @property string $zip
 * @property float|null $lattitude
 * @property float|null $longitude
 * @property CarbonInterface|null $deleted_at
 * @property-read string $cityState
 * @property-read string $fullAddress
 * @property-read string $profileURL
 * @property-read string $firstNameLastInitial
 * @property-read string $fullName
 * @property-read string $imageURL
 * @property-read string $imagePath
 */
#[ObservedBy([UserObserver::class])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasLegacyDates, SoftDeletes;

    protected $table = 'user';

    public $timestamps = true;

    protected $guarded = ['id', 'is_admin', 'type_id', 'password', 'remember_token', 'deleted_at', 'date_registered'];

    protected $hidden = ['password', 'remember_token'];

    protected $appends = [
        'cityState',
        'fullAddress',
        'profileURL',
        'firstNameLastInitial',
        'fullName',
        'imageURL',
    ];

    /** Columns that, when changed, require the coordinates to be looked up again. */
    public const ADDRESS_FIELDS = ['address1', 'address2', 'city', 'state', 'zip'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_registered' => 'datetime',
            'date_updated' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'type_id' => UserType::class,
            'location_id' => 'integer',
            'is_confirmed' => 'integer',
            'is_admin' => 'boolean',
            'has_image' => 'boolean',
            'lattitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Sets the password to a bcrypt hash of $password only when $password and $confirm are equal
     * and $password is at least 6 characters long (legacy rule). Otherwise nothing changes and
     * false is returned. Does not save; the caller persists the model.
     */
    public function setPasswordWithConfirmation(string $password, string $confirm): bool
    {
        if (trim($password) === '' || strlen($password) < 6 || ! hash_equals($password, $confirm)) {
            return false;
        }

        $this->password = Hash::make($password);

        return true;
    }

    /**
     * Legacy "City, ST" (unmasked).
     */
    public function getCityStateAttribute(): string
    {
        return $this->city.', '.$this->state;
    }

    /**
     * Address on one line, in the same layout as the legacy app.
     */
    public function getFullAddressAttribute(): string
    {
        return $this->address1.' '.$this->address2.' '.$this->city.', '.$this->state.' '.$this->zip;
    }

    /**
     * Legacy profile hash, e.g. "#/EugeneS/u12": ucfirst(first) + ucfirst(last initial), then the id.
     */
    public function getProfileURLAttribute(): string
    {
        return '#/'.ucfirst((string) $this->first).ucfirst(substr((string) $this->last, 0, 1)).'/u'.$this->id;
    }

    /**
     * "First L" (unmasked).
     */
    public function getFirstNameLastInitialAttribute(): string
    {
        return $this->first.' '.substr((string) $this->last, 0, 1);
    }

    /**
     * "First Last" (unmasked).
     */
    public function getFullNameAttribute(): string
    {
        return $this->first.' '.$this->last;
    }

    /**
     * Profile image URL. Members with an image get the member image with a filemtime cache-bust
     * (only when the file exists); everyone else gets the generic avatar.
     */
    public function getImageURLAttribute(): string
    {
        if (! $this->has_image) {
            return config('sesf.image_url').'generic_avatar_300.gif';
        }

        $file = $this->imagePath.'profile.jpg';
        $bust = File::isFile($file) ? '?'.File::lastModified($file) : '';

        return config('sesf.member_image_url').$this->id.'/profile.jpg'.$bust;
    }

    /**
     * Filesystem directory holding this member's images.
     */
    public function getImagePathAttribute(): string
    {
        return config('sesf.member_image_root').$this->id.'/';
    }

    /**
     * Cars owned by the user (legacy cars()).
     *
     * @return HasMany<Car, $this>
     */
    public function cars(): HasMany
    {
        return $this->hasMany(Car::class, 'user_id');
    }

    /**
     * Show registrations (legacy registrations()).
     *
     * @return HasMany<ShowRegistration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(ShowRegistration::class, 'user_id');
    }

    /**
     * Judge assignments (legacy carShowJudgeAssignments()).
     *
     * @return HasMany<CarShowJudge, $this>
     */
    public function carShowJudgeAssignments(): HasMany
    {
        return $this->hasMany(CarShowJudge::class, 'user_id');
    }

    /**
     * Email sign-in requests (login_email_signin).
     *
     * @return HasMany<LoginEmailSignin, $this>
     */
    public function loginEmailSignins(): HasMany
    {
        return $this->hasMany(LoginEmailSignin::class, 'user_id');
    }

    /**
     * @return HasOne<UserPreferences, $this>
     */
    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreferences::class, 'user_id');
    }
}
