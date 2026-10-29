<?php

namespace App\Http\Resources\Admin;

use App\Models\ShowRegistration;
use App\Models\UserGuest;
use App\Services\Admin\CheckinService;
use App\Support\Admin\DisplayName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A registration on the check-in list, with the fields of legacy CheckinList::registrationToCheckinRow: party waiver
 * state, payment, check-in time, arrival, and the shirt lines (for hand-out). Expects CheckinService::activeQuery().
 *
 * @mixin ShowRegistration
 */
final class CheckinRegistrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ShowRegistration $registration */
        $registration = $this->resource;

        return [
            'id' => (int) $registration->id,
            'invoice_id' => (int) $registration->invoice_id,
            'primary_name' => DisplayName::person($registration->user),
            'primary_email' => self::email($registration),
            'is_paid' => (bool) $registration->is_paid,
            'is_checked_in' => (bool) $registration->is_checked_in,
            'checked_in_at' => $registration->date_checked_in?->toIso8601String(),
            'arrival_date' => $registration->arrival_date?->format('Y-m-d'),
            'arrival_time' => $registration->arrival_time,
            'party_complete' => CheckinService::partyComplete($registration),
            'party' => $this->party($registration),
            'shirt_lines' => $registration->shirts
                ->sortBy([['type_id', 'asc'], ['id', 'asc']])
                ->values()
                ->map(fn ($shirt): array => [
                    'id' => (int) $shirt->id,
                    'type_id' => (int) $shirt->type_id,
                    'type_name' => DisplayName::shirtType($shirt->shirtType, (int) $shirt->type_id),
                    'handed_out' => (bool) $shirt->is_handed_out,
                ])
                ->all(),
        ];
    }

    private static function email(ShowRegistration $registration): ?string
    {
        $email = trim((string) $registration->user?->email);

        return $email === '' ? null : $email;
    }

    /**
     * @return list<array{role: string, name: string, signed: bool, guest_id: int|null}>
     */
    private function party(ShowRegistration $registration): array
    {
        $party = [[
            'role' => 'primary',
            'name' => DisplayName::person($registration->user),
            'signed' => (bool) $registration->getAttribute('primary_waiver_signed'),
            'guest_id' => null,
        ]];

        foreach ($registration->guests as $guest) {
            /** @var UserGuest $guest */
            $party[] = [
                'role' => 'guest',
                'name' => trim($guest->first.' '.$guest->last),
                'signed' => (bool) $guest->waiver?->has_signed,
                'guest_id' => (int) $guest->id,
            ];
        }

        return $party;
    }
}
