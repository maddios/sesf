<?php

namespace App\Services\Waivers;

/**
 * Reads waiver.waiver_data in any of the three stored shapes, for PDF regeneration and admin lists.
 *
 *  - FORMAT_CURRENT (this app): first_name, last_name, email, signature_png_base64, signature_mode,
 *    signature_text?, is_minor, guardian_name?, geolocation?, user_agent, ip_hash, signed_at, show_id.
 *  - FORMAT_LEGACY_NATIVE (legacy WaiverSignService, internal_version 1): show_id, first_name,
 *    last_name, email, signing_for_minor, adult_first_name, adult_last_name, signature_mode,
 *    signature_image_base64.
 *  - FORMAT_LEGACY_THIRD_PARTY (2019-era WaiverForever API response stored verbatim):
 *    {result, msg, data: {id, tracking_id, template_title, geolocation, data: [fields...]}}. The
 *    signer's name and email are read from the field list; no signature image was kept locally
 *    (it stayed with the provider and is never fetched).
 */
final class SignedWaiverData
{
    public const FORMAT_CURRENT = 'sesf-2';

    public const FORMAT_LEGACY_NATIVE = 'legacy-native';

    public const FORMAT_LEGACY_THIRD_PARTY = 'legacy-third-party';

    public const FORMAT_UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $format,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly string $guardianName,
        public readonly ?string $signaturePngBase64,
        public readonly string $typedText,
        public readonly ?int $showId,
        public readonly string $templateTitle,
    ) {}

    /**
     * @param  array<int|string, mixed>|null  $data
     */
    public static function read(?array $data): self
    {
        if ($data === null || $data === []) {
            return self::unknown();
        }

        if (($data['format'] ?? null) === self::FORMAT_CURRENT) {
            return new self(
                self::FORMAT_CURRENT,
                self::str($data['first_name'] ?? ''),
                self::str($data['last_name'] ?? ''),
                self::str($data['email'] ?? ''),
                self::str($data['guardian_name'] ?? ''),
                self::nonEmpty($data['signature_png_base64'] ?? null),
                self::str($data['signature_text'] ?? ''),
                self::int($data['show_id'] ?? null),
                '',
            );
        }

        if (array_key_exists('internal_version', $data) || array_key_exists('signature_image_base64', $data)) {
            $minor = ! empty($data['signing_for_minor']);
            $guardian = $minor
                ? trim(self::str($data['adult_first_name'] ?? '').' '.self::str($data['adult_last_name'] ?? ''))
                : '';

            return new self(
                self::FORMAT_LEGACY_NATIVE,
                self::str($data['first_name'] ?? ''),
                self::str($data['last_name'] ?? ''),
                self::str($data['email'] ?? ''),
                $guardian,
                self::nonEmpty($data['signature_image_base64'] ?? null),
                '',
                self::int($data['show_id'] ?? null),
                '',
            );
        }

        if (array_key_exists('result', $data) && isset($data['data']) && is_array($data['data'])) {
            return self::readThirdParty($data['data']);
        }

        return self::unknown();
    }

    public function isKnown(): bool
    {
        return $this->format !== self::FORMAT_UNKNOWN;
    }

    /**
     * @param  array<int|string, mixed>  $payload
     */
    private static function readThirdParty(array $payload): self
    {
        $first = self::str($payload['first_name'] ?? '');
        $last = self::str($payload['last_name'] ?? '');
        $email = self::str($payload['email'] ?? '');

        $fields = $payload['data'] ?? [];
        if (is_array($fields)) {
            foreach ($fields as $field) {
                if (! is_array($field)) {
                    continue;
                }
                if ($first === '' && $last === '' && (isset($field['first_name']) || isset($field['last_name']))) {
                    $first = self::str($field['first_name'] ?? '');
                    $last = self::str($field['last_name'] ?? '');
                }
                $type = strtolower(self::str($field['type'] ?? ''));
                if ($email === '' && str_contains($type, 'email')) {
                    $email = self::str($field['value'] ?? '');
                }
            }
        }

        return new self(
            self::FORMAT_LEGACY_THIRD_PARTY,
            $first,
            $last,
            $email,
            '',
            null,
            '',
            null,
            self::str($payload['template_title'] ?? ''),
        );
    }

    private static function unknown(): self
    {
        return new self(self::FORMAT_UNKNOWN, '', '', '', '', null, '', null, '');
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
