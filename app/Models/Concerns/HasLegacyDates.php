<?php

namespace App\Models\Concerns;

/**
 * Lets Eloquent models read and write the legacy zero dates ('0000-00-00 00:00:00' and
 * '0000-00-00') that the MariaDB schema uses as "never set".
 *
 * Reads: a zero date, null or '' becomes null for date/datetime casts, created_at and
 * updated_at, and toArray()/toJson(). Dates are never turned into a bogus Carbon.
 *
 * Writes: null and '' are stored as null. A zero date string is written back unchanged,
 * so NOT NULL legacy timestamp columns keep their zero value instead of failing.
 *
 * Use only the 'date' and 'datetime' casts. The immutable and 'timestamp' casts are not
 * covered.
 */
trait HasLegacyDates
{
    protected function asDateTime($value)
    {
        return $this->isLegacyEmptyDate($value) ? null : parent::asDateTime($value);
    }

    protected function asDate($value)
    {
        return $this->isLegacyEmptyDate($value) ? null : parent::asDate($value);
    }

    public function fromDateTime($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($this->isLegacyZeroDate($value)) {
            return $value;
        }

        return parent::fromDateTime($value);
    }

    /**
     * Same as the parent, except that a zero date becomes null instead of being serialized.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function addDateAttributesToArray(array $attributes)
    {
        foreach ($this->getDates() as $key) {
            if (! array_key_exists($key, $attributes)) {
                continue;
            }

            $attributes[$key] = $this->isLegacyEmptyDate($attributes[$key])
                ? null
                : $this->serializeDate($this->asDateTime($attributes[$key]));
        }

        return $attributes;
    }

    private function isLegacyEmptyDate(mixed $value): bool
    {
        return $value === null || $value === '' || $this->isLegacyZeroDate($value);
    }

    private function isLegacyZeroDate(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, '0000-00-00');
    }
}
