<?php

namespace App\Http\Resources\Admin;

use App\Models\ShowRegistration;
use App\Services\Registration\RegistrationPricing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A registration as the admin sees it. Amounts are integer cents. The summary is the stored one for
 * ended shows and computed from the current prices otherwise (amount_source says which);
 * stored_owed_cents is always the amount_owed column. Expects cars, guests, shirts and extras eager
 * loaded (RegistrationController::query) so the list does not query per row.
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
            // The full on-behalf editor (legacy "Edit Registration" action), a hash route of the SPA.
            'editor_path' => '/'.$registration->carShow?->URLName.'/e'.$registration->show_id.'/register?user_id='.$registration->user_id,
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
            'stored_owed_cents' => RegistrationPricing::signedCents((string) $registration->amount_owed),
            'received_actual_cents' => $registration->amount_received_actual === null ? null : RegistrationPricing::signedCents((string) $registration->amount_received_actual),
            'discount_note' => $registration->discount_note,
            'car_ids' => $registration->cars->modelKeys(),
            'cars_count' => $registration->cars->count(),
            'guests_count' => $registration->guests->count(),
            'shirts_count' => $registration->shirts->count(),
            'created_at' => $registration->created_at?->toIso8601String(),
            'updated_at' => $registration->updated_at?->toIso8601String(),
        ];
    }
}
