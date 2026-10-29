<?php

namespace App\Services\Waivers;

/**
 * What the signer submitted, already validated for shape and width by
 * App\Http\Requests\Waivers\SignWaiverRequest. The signature data URL is checked and re-encoded by
 * SignatureImage inside WaiverSignService::sign().
 */
final class SignPayload
{
    /**
     * @param  'draw'|'type'  $mode
     * @param  array{lat: float, lng: float, accuracy: float|null}|null  $geolocation
     */
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly string $mode,
        public readonly string $signatureDataUrl,
        public readonly string $typedText = '',
        public readonly bool $isMinor = false,
        public readonly string $guardianFirst = '',
        public readonly string $guardianLast = '',
        public readonly ?array $geolocation = null,
    ) {}
}
