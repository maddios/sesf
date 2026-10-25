<?php

namespace App\Services\Registration;

use App\Models\ShowRegistration;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Loads the invoice for legacy MemberProfile::getInvoice and getInvoiceList.
 *
 * Read-only. Legacy getInvoice wrote amount_owed (updateInvoiceAmounts); that write is dropped,
 * see docs/pricing.md.
 */
final class InvoiceBuilder
{
    /**
     * Registration for the invoice, with relations. Cancelled registrations are hidden (legacy
     * whereIsCancelled(0)). Access (owner or admin) is checked by the controller.
     */
    public function find(int $registrationId): ?ShowRegistration
    {
        return ShowRegistration::query()
            ->with(['carShow', 'cars.body', 'guests', 'shirts.shirtType', 'extras.showExtra'])
            ->where('id', $registrationId)
            ->where('is_cancelled', false)
            ->first();
    }

    /**
     * Invoice list for a member: uncancelled registrations, newest first (legacy getInvoiceList).
     *
     * @return Collection<int, ShowRegistration>
     */
    public function listFor(User $user): Collection
    {
        return ShowRegistration::query()
            ->with('carShow:id,name')
            ->where('user_id', $user->id)
            ->where('is_cancelled', false)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'show_id', 'created_at']);
    }
}
