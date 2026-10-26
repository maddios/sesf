<?php

namespace App\Services\Waivers;

use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Models\UserGuest;
use App\Models\Waiver;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Throwable;

/**
 * Waiver lifecycle (port of legacy WaiverSignService): pending rows, prefill, signing and PDFs.
 *
 * Secrets: tracking_id (128-bit random, 32 hex) is the capability in the sign link. It is never
 * logged; log lines use the numeric row id. waiver_id (64-bit random, 16 hex) is the public id
 * set at signing.
 *
 * Ownership is circular, as in the legacy schema: waiver.show_registration_id or waiver.guest_id
 * points at the owner, and show_registration.waiver_id / user_guest.waiver_id point back.
 */
class WaiverSignService
{
    public const ADHOC_SESSION_PREFIX = 'waiver_sign_adhoc_';

    public function __construct(
        private readonly SignatureImage $signature,
        private readonly WaiverPdf $pdf,
    ) {}

    /** A new pending waiver for a registrant (no guest) or a guest, attached in both directions. */
    public function createPending(ShowRegistration $registration, ?UserGuest $guest = null): Waiver
    {
        return DB::transaction(function () use ($registration, $guest): Waiver {
            $waiver = $this->newPendingRow($guest !== null
                ? ['guest_id' => $guest->id]
                : ['show_registration_id' => $registration->id]);

            if ($guest !== null) {
                UserGuest::query()->whereKey($guest->id)->update(['waiver_id' => $waiver->id]);
                $guest->setAttribute('waiver_id', $waiver->id);
            } else {
                ShowRegistration::query()->whereKey($registration->id)->update(['waiver_id' => $waiver->id]);
                $registration->setAttribute('waiver_id', $waiver->id);
            }

            return $waiver;
        });
    }

    /**
     * The person's current waiver (signed or not), or a new pending one. The owner row is locked
     * so two concurrent calls do not create two waivers for one person.
     */
    public function ensurePending(ShowRegistration $registration, ?UserGuest $guest = null): Waiver
    {
        return DB::transaction(function () use ($registration, $guest): Waiver {
            if ($guest !== null) {
                if ((int) $guest->registration_id !== (int) $registration->id) {
                    throw new WaiverNotFound('Invalid guest.');
                }
                $owner = UserGuest::query()->whereKey($guest->id)->lockForUpdate()->first();
                $column = 'guest_id';
            } else {
                $owner = ShowRegistration::query()->whereKey($registration->id)->lockForUpdate()->first();
                $column = 'show_registration_id';
            }
            if ($owner === null) {
                throw new WaiverNotFound('Not found');
            }

            $waiver = $owner->waiver_id !== null ? Waiver::query()->find($owner->waiver_id) : null;
            $waiver ??= Waiver::query()->where($column, $owner->id)->orderByDesc('id')->first();
            if ($waiver !== null) {
                if ((int) $owner->waiver_id !== (int) $waiver->id) {
                    $owner->newQuery()->whereKey($owner->id)->update(['waiver_id' => $waiver->id]);
                }

                return $waiver;
            }

            return $this->createPending($registration, $guest);
        });
    }

    /** A new pending walk-in waiver for a show (no registration or guest). */
    public function createAdhoc(CarShow $show): Waiver
    {
        return $this->newPendingRow(['adhoc_show_id' => $show->id]);
    }

    /**
     * The walk-in waiver bound to this browser session for the show. An unsigned one is reused;
     * a new one costs one hit on the per-IP hourly budget (AdhocLimitReached when spent).
     */
    public function adhocForSession(CarShow $show, Session $session, string $ip): Waiver
    {
        $key = self::ADHOC_SESSION_PREFIX.$show->id;
        $existing = $this->adhocFromSession($show, $session);
        if ($existing !== null) {
            return $existing;
        }

        $limiterKey = 'waiver-adhoc-create|'.$ip;
        $max = max(1, (int) config('waivers.adhoc_creates_per_ip_per_hour', 30));
        if (RateLimiter::tooManyAttempts($limiterKey, $max)) {
            throw new AdhocLimitReached(RateLimiter::availableIn($limiterKey));
        }
        RateLimiter::hit($limiterKey, 3600);

        $waiver = $this->createAdhoc($show);
        $session->put($key, $waiver->getAttribute('tracking_id'));

        return $waiver;
    }

    /** The unsigned walk-in waiver this session holds for the show, or null (stale keys are dropped). */
    public function adhocFromSession(CarShow $show, Session $session): ?Waiver
    {
        $key = self::ADHOC_SESSION_PREFIX.$show->id;
        $tracking = $session->get($key);
        $waiver = is_string($tracking) ? $this->findByTrackingId($tracking) : null;
        if ($waiver === null || $waiver->has_signed || (int) $waiver->adhoc_show_id !== (int) $show->id) {
            $session->forget($key);

            return null;
        }

        return $waiver;
    }

    /** Deletes unsigned walk-in waivers older than $days (abandoned sessions). Returns the count. */
    public function pruneStaleAdhoc(int $days = 7): int
    {
        return Waiver::query()
            ->whereNotNull('adhoc_show_id')
            ->where('has_signed', false)
            ->where('created_at', '<', now()->subDays(max(1, $days)))
            ->delete();
    }

    public function findByTrackingId(string $trackingId): ?Waiver
    {
        $trackingId = trim($trackingId);
        if ($trackingId === '' || strlen($trackingId) > 32 || preg_match('/\A[A-Za-z0-9_-]+\z/', $trackingId) !== 1) {
            return null;
        }

        return Waiver::query()->where('tracking_id', $trackingId)->first();
    }

    /** The event and person behind the waiver, or WaiverNotFound. */
    public function context(Waiver $waiver): SignerContext
    {
        if ($waiver->isAdhoc()) {
            $show = CarShow::query()->find($waiver->adhoc_show_id);
            if ($show === null) {
                throw new WaiverNotFound('Event not found');
            }

            return new SignerContext($show, null, null, '', '', '');
        }

        if ($waiver->guest_id !== null) {
            $guest = UserGuest::query()->find($waiver->guest_id);
            $registration = $guest !== null ? ShowRegistration::query()->find($guest->registration_id) : null;
            $show = $registration !== null ? CarShow::query()->find($registration->show_id) : null;
            if ($guest === null || $show === null) {
                throw new WaiverNotFound('Not found');
            }

            return new SignerContext($show, $registration, $guest, (string) $guest->first, (string) $guest->last, (string) $guest->email);
        }

        $registration = $waiver->show_registration_id !== null ? ShowRegistration::query()->find($waiver->show_registration_id) : null;
        $user = $registration !== null ? User::query()->find($registration->user_id) : null;
        $show = $registration !== null ? CarShow::query()->find($registration->show_id) : null;
        if ($registration === null || $user === null || $show === null) {
            throw new WaiverNotFound('Not found');
        }

        return new SignerContext($show, $registration, null, (string) $user->first, (string) $user->last, (string) $user->email);
    }

    /**
     * Prefill for the signing form: first, last and a masked email. Unknown link: WaiverNotFound
     * (404); signed: WaiverAlreadySigned (409).
     *
     * @return array{first: string, last: string, email: string}
     */
    public function prefill(Waiver $waiver): array
    {
        $context = $this->context($waiver);
        if ($waiver->has_signed) {
            throw new WaiverAlreadySigned;
        }

        return [
            'first' => $context->first,
            'last' => $context->last,
            'email' => self::maskEmail($context->email),
        ];
    }

    /**
     * Signs the waiver once. The signature is validated and re-encoded first; then, in one
     * transaction, the row is locked, checked unsigned, filled and the participant flagged. The PDF
     * is written after commit: a PDF failure never loses a signature (admins regenerate it).
     *
     * @throws WaiverAlreadySigned|WaiverNotFound|InvalidSignature
     */
    public function sign(Waiver $waiver, SignPayload $payload, ?string $ip, ?string $userAgent): Waiver
    {
        if ($waiver->has_signed) {
            throw new WaiverAlreadySigned;
        }

        $png = $this->signature->fromDataUrl($payload->signatureDataUrl);

        /** @var Waiver $signed */
        $signed = DB::transaction(function () use ($waiver, $payload, $png, $ip, $userAgent): Waiver {
            $locked = Waiver::query()->whereKey($waiver->id)->lockForUpdate()->first();
            if ($locked === null) {
                throw new WaiverNotFound('Not found');
            }
            if ($locked->has_signed || ! $locked->isPending()) {
                throw new WaiverAlreadySigned;
            }

            $context = $this->context($locked);
            $signedAt = now();

            $data = array_filter([
                'format' => SignedWaiverData::FORMAT_CURRENT,
                'show_id' => (int) $context->show->id,
                'first_name' => $payload->firstName,
                'last_name' => $payload->lastName,
                'email' => $payload->email,
                'signature_mode' => $payload->mode,
                'signature_text' => $payload->mode === 'type' && $payload->typedText !== '' ? $payload->typedText : null,
                'signature_png_base64' => $png,
                'is_minor' => $payload->isMinor,
                'guardian_name' => $payload->isMinor ? trim($payload->guardianFirst.' '.$payload->guardianLast) : null,
                'geolocation' => $payload->geolocation,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                'ip_hash' => $ip !== null && $ip !== '' ? self::ipHash($ip) : null,
                'signed_at' => $signedAt->toIso8601String(),
            ], fn (mixed $v): bool => $v !== null);

            $json = json_encode($data);
            if ($json === false || strlen($json) > (int) config('waivers.waiver_data_max_bytes', 65535)) {
                throw InvalidSignature::tooLarge();
            }

            $locked->forceFill([
                'waiver_data' => $data,
                'has_signed' => true,
                'signed_at' => $signedAt,
                'waiver_id' => $this->uniquePublicId(),
            ])->save();

            if ($locked->guest_id !== null) {
                UserGuest::query()->whereKey($locked->guest_id)->update(['has_signed' => true]);
            } elseif ($locked->show_registration_id !== null) {
                ShowRegistration::query()->whereKey($locked->show_registration_id)->update(['has_signed' => true]);
            }

            return $locked;
        });

        try {
            $this->writePdf($signed);
        } catch (Throwable $e) {
            Log::error('Waiver PDF write failed after signing', ['waiver_row' => $signed->id, 'error' => $e->getMessage()]);
        }

        return $signed->refresh();
    }

    /**
     * Renders and stores the PDF of a signed waiver from waiver_data (any stored shape) and sets
     * has_pdf. False when the data cannot produce a faithful PDF.
     */
    public function writePdf(Waiver $waiver): bool
    {
        $publicId = (string) $waiver->waiver_id;
        if (! $waiver->has_signed || preg_match(WaiverPdf::WAIVER_ID_PATTERN, $publicId) !== 1) {
            return false;
        }

        $data = SignedWaiverData::read($waiver->waiver_data);
        if (! $data->isKnown()) {
            return false;
        }

        $show = $data->showId !== null ? CarShow::query()->find($data->showId) : null;
        if ($show === null) {
            try {
                $show = $this->context($waiver)->show;
            } catch (WaiverNotFound) {
                return false;
            }
        }

        $note = '';
        if ($data->format === SignedWaiverData::FORMAT_LEGACY_THIRD_PARTY) {
            $note = 'Signed electronically through WaiverForever (record '.$publicId.')'
                .($data->templateTitle !== '' ? ', template "'.$data->templateTitle.'"' : '')
                .'. The signature image was kept by that provider and is not reproduced here. This copy was'
                .' regenerated from stored data; the agreement wording above is the current SESF waiver and'
                .' may differ from the original template.';
        } else {
            // Re-check stored images before dompdf parses them (legacy rows were never re-encoded).
            if ($data->signaturePngBase64 === null) {
                return false;
            }
            try {
                $this->signature->fromBase64($data->signaturePngBase64);
            } catch (InvalidSignature) {
                Log::warning('Stored waiver signature failed validation', ['waiver_row' => $waiver->id]);

                return false;
            }
        }

        $html = $this->pdf->signedHtml($show, $data, $waiver->signed_at, $note);
        $this->pdf->store($publicId, $this->pdf->render($html));

        $waiver->forceFill(['has_pdf' => true])->save();

        return true;
    }

    /**
     * Path of the stored PDF for an admin download: the stored file, else a legacy public/waivers
     * copy moved into storage, else a regenerated PDF. Null when none can be produced.
     */
    public function pdfPathForAdmin(Waiver $waiver): ?string
    {
        $publicId = (string) $waiver->waiver_id;
        if (! $waiver->has_signed || preg_match(WaiverPdf::WAIVER_ID_PATTERN, $publicId) !== 1) {
            return null;
        }

        $path = $this->pdf->path($publicId);
        if (! is_file($path)) {
            $legacyDir = (string) config('waivers.legacy_public_dir');
            $legacy = $legacyDir !== '' ? realpath($legacyDir.'/'.$publicId.'.pdf') : false;
            $legacyRoot = $legacyDir !== '' ? realpath($legacyDir) : false;
            if ($legacy !== false && $legacyRoot !== false && str_starts_with($legacy, $legacyRoot.'/') && is_file($legacy)) {
                $bytes = (string) file_get_contents($legacy);
                if (str_starts_with($bytes, '%PDF-')) {
                    $this->pdf->store($publicId, $bytes);
                }
            }
        }

        if (! is_file($path) && ! $this->writePdf($waiver)) {
            return null;
        }

        if (! $waiver->has_pdf) {
            $waiver->forceFill(['has_pdf' => true])->save();
        }

        return $path;
    }

    /** The unsigned paper form for walk-ins (legacy blankGenericWaiverPdfBinary). */
    public function blankPdf(CarShow $show): string
    {
        return $this->pdf->render($this->pdf->blankHtml($show));
    }

    /** "j***@example.com": the first character of the local part and the domain. */
    public static function maskEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '') {
            return '';
        }
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }

    /** Keyed hash of the signer's IP (HMAC-SHA256 with the app key); the IP itself is not stored. */
    public static function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, 'sesf-waiver-ip|'.config('app.key'));
    }

    /**
     * @param  array<string, int>  $owner
     */
    private function newPendingRow(array $owner): Waiver
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $waiver = new Waiver;
                $waiver->forceFill(array_merge([
                    'tracking_id' => bin2hex(random_bytes(16)),
                    'request_waiver_url' => null,
                    'has_signed' => false,
                    'has_pdf' => false,
                ], $owner))->save();

                return $waiver;
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new RuntimeException('Could not allocate a waiver tracking id.');
    }

    private function uniquePublicId(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $id = bin2hex(random_bytes(8));
            if (! Waiver::query()->where('waiver_id', $id)->exists()) {
                return $id;
            }
        }

        throw new RuntimeException('Could not allocate a public waiver id.');
    }
}
