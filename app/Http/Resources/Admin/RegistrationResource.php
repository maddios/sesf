<?php

namespace App\Http\Resources\Admin;

use App\Models\ShowRegistration;
use App\Services\Registration\RegistrationPricing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A registration as the admin sees it. Amounts are integer cents. The summary is the stored one for
 * ended shows and computed from the current prices otherwise (amount_source says which).
 *
 * @mixin ShowRegistration
 */
final class RegistrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ShowRegistration $registration */
        $registration = $this->resource;
        $totals = app(RegistrationPricing::class)->totalsFor($registration);

        return [
            'id' => (int) $registration->id,
            'invoice_id' => (int) $registration->invoice_id,
            'show_id' => (int) $registration->show_id,
            'show_name' => $registration->show_name,
            'user_id' => (int) $registration->user_id,
            'user_name' => $registration->user?->fullName,
            'donation_cents' => (int) $registration->donation * 100,
            'arrival_date' => $registration->arrival_date?->format('Y-m-d'),
            'arrival_time' => $registration->arrival_time,
            'is_cancelled' => (bool) $registration->is_cancelled,
            'is_paid' => (bool) $registration->is_paid,
            'amount_source' => $totals['source'],
            'total_cents' => RegistrationPricing::signedCents($totals['total']),
            'owed_cents' => RegistrationPricing::signedCents($totals['owed']),
            'received_cents' => RegistrationPricing::signedCents($totals['received']),
            'discount_cents' => RegistrationPricing::signedCents($totals['discount']),
            'cars_count' => (int) ($registration->getAttribute('cars_count') ?? 0),
            'guests_count' => (int) ($registration->getAttribute('guests_count') ?? 0),
            'shirts_count' => (int) ($registration->getAttribute('shirts_count') ?? 0),
            'created_at' => $registration->created_at?->toIso8601String(),
            'updated_at' => $registration->updated_at?->toIso8601String(),
        ];
    }
}
