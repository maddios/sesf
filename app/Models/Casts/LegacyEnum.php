<?php

namespace App\Models\Casts;

use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Enum cast for legacy columns that have NO foreign key in the DDL. An unexpected stored value
 * (a retired id, a typo, a blank) must not throw ValueError on load or serialize, so the cast
 * never calls the strict from() and reads through tryFromLegacy() instead.
 *
 * Columns WITH a foreign key keep the native enum cast: the database guarantees the value exists.
 *
 * get(): the enum case for a known value; null for null, blank or unknown values.
 * set(): accepts the enum case, an int, or a numeric string, and stores the backing int. An unknown
 *        int is stored as-is. Null stays null. Other input throws InvalidArgumentException.
 *
 * Use it as LegacyEnum::of(UserType::class) in casts(), which is the cast string form.
 * The enum must be int-backed and use App\Enums\Concerns\TriesLegacyValues.
 *
 * @implements CastsAttributes<BackedEnum|null, BackedEnum|int|string|null>
 */
final class LegacyEnum implements CastsAttributes
{
    /** @var class-string<BackedEnum> */
    private readonly string $enumClass;

    /**
     * @param  class-string<BackedEnum>  $enumClass
     */
    public function __construct(string $enumClass)
    {
        if (! enum_exists($enumClass) || ! is_subclass_of($enumClass, BackedEnum::class)
            || ! method_exists($enumClass, 'tryFromLegacy')) {
            throw new InvalidArgumentException("[{$enumClass}] must be a backed enum that uses TriesLegacyValues.");
        }

        $this->enumClass = $enumClass;
    }

    /**
     * The cast string for a model's casts() array, e.g. 'type_id' => LegacyEnum::of(UserType::class).
     * Eloquent only accepts cast objects that are Stringable, so this returns the string form
     * 'App\Models\Casts\LegacyEnum:App\Enums\UserType', which Eloquent turns into new LegacyEnum(...).
     *
     * @param  class-string<BackedEnum>  $enumClass
     */
    public static function of(string $enumClass): string
    {
        return self::class.':'.$enumClass;
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?BackedEnum
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        return ($this->enumClass)::tryFromLegacy($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int|string|null
    {
        if ($value === null) {
            return null;
        }

        $enumClass = $this->enumClass;

        if ($value instanceof $enumClass) {
            return $value->value;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $id = filter_var(trim($value), FILTER_VALIDATE_INT);

            if (is_int($id)) {
                return $id;
            }
        }

        throw new InvalidArgumentException("Value for [{$key}] must be a {$enumClass} case, an int or a numeric string.");
    }
}
