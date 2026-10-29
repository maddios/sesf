<?php

namespace App\Support\Admin;

use App\Models\Casts\Money2;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Admin audit trail: one JSON line per admin write, on the dedicated daily `audit` log channel
 * (config/logging.php; not part of the default stack).
 *
 * Each entry: who (user id), action (e.g. sponsor.update), resource, ids, changed fields, ip,
 * request id, method and path. Changed fields carry old -> new values only for fields that are
 * not sensitive; sensitive fields (passwords, tokens, contact details, free-text notes, plus any
 * field the caller lists in $redact) are logged by name only. Long values are truncated.
 *
 * The audit.admin middleware (App\Http\Middleware\AuditAdminWrites) gives every request a request
 * id and writes a fallback entry for a successful write whose controller recorded nothing.
 */
final class AuditLog
{
    public const CHANNEL = 'audit';

    public const REQUEST_ID_ATTRIBUTE = 'audit.request_id';

    public const RECORDED_ATTRIBUTE = 'audit.recorded';

    public const MAX_VALUE_LENGTH = 200;

    public const REDACTED = '[redacted]';

    /**
     * Field-name tokens (split on "_", "-" and camelCase, lower case) that are never logged with
     * their values: credentials, contact details, location, identity numbers and free-text notes.
     */
    private const SENSITIVE_TOKENS = [
        'password', 'passwd', 'pass', 'token', 'secret', 'remember', 'hash', 'key', 'otp', 'code',
        'email', 'phone', 'mobile', 'fax', 'street', 'street2', 'address', 'city', 'zip', 'postal',
        'lattitude', 'latitude', 'longitude', 'lat', 'lng', 'birth', 'birthday', 'dob', 'ssn',
        'license', 'vin', 'note', 'notes', 'ip', 'first', 'last', 'signature', 'emergency',
    ];

    /** Bookkeeping columns that are not worth an entry. */
    private const IGNORED = ['updated_at', 'created_at'];

    /**
     * Writes one entry.
     *
     * @param  list<int|string>  $ids
     * @param  array<string, array{old: mixed, new: mixed}|string>  $changes  from diff()/created(), or field => '[redacted]'
     * @param  array<string, scalar|null>  $extra  small extra context (counts, flags); never personal data
     */
    public static function record(string $action, string $resource, array $ids, array $changes = [], array $extra = [], ?Request $request = null): void
    {
        $request ??= request();
        $user = $request->user();

        $entry = [
            'event' => 'admin.audit',
            'at' => now()->toIso8601String(),
            'user_id' => $user?->getAuthIdentifier(),
            'action' => $action,
            'resource' => $resource,
            'ids' => array_map(fn ($id) => is_numeric($id) ? (int) $id : (string) $id, $ids),
            'changes' => (object) $changes,
            'ip' => $request->ip(),
            'request_id' => self::requestId($request),
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
        ];
        if ($extra !== []) {
            $entry['extra'] = $extra;
        }

        Log::channel(self::CHANNEL)->info($action, $entry);
        $request->attributes->set(self::RECORDED_ATTRIBUTE, true);
    }

    /**
     * Changed fields of a model that was just saved: [field => {old, new}] (redacted where sensitive).
     *
     * @param  list<string>  $redact  extra fields to log by name only
     * @return array<string, array{old: mixed, new: mixed}|string>
     */
    public static function diff(Model $model, array $redact = []): array
    {
        $previous = $model->getPrevious();
        $out = [];
        foreach ($model->getChanges() as $field => $new) {
            $field = (string) $field;
            if (in_array($field, self::IGNORED, true)) {
                continue;
            }
            $out[$field] = self::isSensitive($field, $redact)
                ? self::REDACTED
                : ['old' => self::clip(self::typed($model, $field, $previous[$field] ?? null)), 'new' => self::clip(self::typed($model, $field, $new))];
        }

        return $out;
    }

    /**
     * Fields of a newly created model: [field => {old: null, new}] (redacted where sensitive).
     *
     * @param  list<string>  $redact
     * @return array<string, array{old: mixed, new: mixed}|string>
     */
    public static function created(Model $model, array $redact = []): array
    {
        return self::snapshot($model, $redact, fn (mixed $value): array => ['old' => null, 'new' => $value]);
    }

    /**
     * Fields of a model that was just deleted: [field => {old, new: null}] (redacted where sensitive), so a delete can
     * be reconstructed from the log.
     *
     * @param  list<string>  $redact
     * @return array<string, array{old: mixed, new: mixed}|string>
     */
    public static function deleted(Model $model, array $redact = []): array
    {
        return self::snapshot($model, $redact, fn (mixed $value): array => ['old' => $value, 'new' => null]);
    }

    /**
     * The request id: a well-formed incoming X-Request-Id, otherwise a new UUID. Stable for the request.
     */
    public static function requestId(Request $request): string
    {
        $existing = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE);
        if (is_string($existing)) {
            return $existing;
        }

        $header = $request->headers->get('X-Request-Id');
        $id = is_string($header) && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $header) === 1
            ? $header
            : (string) Str::uuid();
        $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $id);

        return $id;
    }

    /**
     * @param  list<string>  $redact
     */
    public static function isSensitive(string $field, array $redact = []): bool
    {
        if (in_array($field, $redact, true)) {
            return true;
        }
        $words = preg_split('/[^a-z0-9]+/', strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $field))) ?: [];

        return array_intersect($words, self::SENSITIVE_TOKENS) !== [];
    }

    /**
     * @param  list<string>  $redact
     * @param  callable(mixed): array{old: mixed, new: mixed}  $entry
     * @return array<string, array{old: mixed, new: mixed}|string>
     */
    private static function snapshot(Model $model, array $redact, callable $entry): array
    {
        $out = [];
        foreach ($model->getAttributes() as $field => $value) {
            $field = (string) $field;
            if (in_array($field, self::IGNORED, true) || $field === $model->getKeyName()) {
                continue;
            }
            $out[$field] = self::isSensitive($field, $redact)
                ? self::REDACTED
                : $entry(self::clip(self::typed($model, $field, $value)));
        }

        return $out;
    }

    /**
     * Raw attribute values as their cast scalar type (bool/int/float), so 1 -> true for boolean columns. Money2
     * columns become decimal strings ("12.30") whether the raw value is a float or a string.
     */
    private static function typed(Model $model, string $field, mixed $value): mixed
    {
        if ($value === null || ! is_scalar($value)) {
            return $value;
        }

        if (str_starts_with((string) ($model->getCasts()[$field] ?? ''), Money2::class)) {
            return (new Money2)->get($model, $field, $value, []);
        }

        return match (true) {
            $model->hasCast($field, ['bool', 'boolean']) => (bool) $value,
            $model->hasCast($field, ['int', 'integer']) => (int) $value,
            $model->hasCast($field, ['float', 'double', 'real']) => (float) $value,
            default => $value,
        };
    }

    private static function clip(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        if (! is_scalar($value)) {
            return '['.get_debug_type($value).']';
        }
        $text = (string) $value;

        return mb_strlen($text) > self::MAX_VALUE_LENGTH ? mb_substr($text, 0, self::MAX_VALUE_LENGTH).'...' : $text;
    }
}
