<?php

namespace App\Http\Resources\Admin;

use App\Models\Waiver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One person on an event's waiver list (see App\Services\Admin\WaiverRoster). can_request is false
 * for a signed waiver or a person without an email; pdf_url is set only when the waiver is signed.
 *
 * @mixin Waiver
 */
final class WaiverRosterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Waiver $row */
        $row = $this->resource;
        $kind = (string) $row->getAttribute('kind');
        $registrationId = (int) $row->getAttribute('registration_id');
        $guestId = $row->guest_id;
        $status = (string) $row->getAttribute('status');
        $email = trim((string) $row->getAttribute('email'));
        $waiverId = $row->waiver_id;

        return [
            'row_key' => $kind.'-'.($guestId ?? $registrationId),
            'kind' => $kind,
            'registration_id' => $registrationId,
            'guest_id' => $guestId,
            'invoice_id' => (int) $row->getAttribute('invoice_id'),
            'first' => (string) $row->getAttribute('first'),
            'last' => (string) $row->getAttribute('last'),
            'email' => $email === '' ? null : $email,
            'status' => $status,
            'signed_at' => $row->signed_at?->toIso8601String(),
            'checked_in' => $row->getAttribute('date_checked_in') !== null,
            'checked_in_at' => $row->getAttribute('date_checked_in') === null ? null : CarbonImmutable::parse((string) $row->getAttribute('date_checked_in'))->toIso8601String(),
            'arrival' => $row->getAttribute('arrival'),
            'can_request' => $email !== '' && $status !== 'signed',
            'pdf_url' => $status === 'signed' && $waiverId !== null ? '/waiver-pdf?'.http_build_query(['waiverId' => $waiverId]) : null,
        ];
    }
}
