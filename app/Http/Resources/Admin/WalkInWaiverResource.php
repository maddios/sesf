<?php

namespace App\Http\Resources\Admin;

use App\Models\Waiver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A signed walk-in waiver for one event. Name and email come from waiver_data (legacy
 * getSignedAdhocWaiversForShow); the PDF link is the admin download.
 *
 * @mixin Waiver
 */
final class WalkInWaiverResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Waiver $waiver */
        $waiver = $this->resource;
        $data = is_array($waiver->waiver_data) ? $waiver->waiver_data : [];
        $email = trim((string) ($data['email'] ?? ''));

        return [
            'waiver_id' => $waiver->waiver_id,
            'first' => trim((string) ($data['first_name'] ?? '')),
            'last' => trim((string) ($data['last_name'] ?? '')),
            'email' => $email === '' ? null : $email,
            'signed_at' => $waiver->signed_at?->toIso8601String(),
            'pdf_url' => $waiver->waiver_id !== null ? '/waiver-pdf?'.http_build_query(['waiverId' => $waiver->waiver_id]) : null,
        ];
    }
}
