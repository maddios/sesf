<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Registration\InvoiceResource;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Services\Registration\InvoiceBuilder;
use App\Services\Registration\RegistrationPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Member invoices (legacy MemberProfile::getInvoice and getInvoiceList). Both routes require
 * sign-in (401 JSON from the auth middleware).
 *
 * Read-only. Legacy getInvoice wrote amount_owed; that write is dropped (docs/pricing.md).
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceBuilder $invoices,
        private readonly RegistrationPricing $pricing,
    ) {}

    /**
     * GET member/invoice-list?user_id: [{id, name}] for one member. Self only; an administrator
     * may pass another user_id. A non-admin passing another user gets 401 (legacy).
     */
    public function list(Request $request): JsonResponse
    {
        $viewer = $request->user();

        if (! $viewer instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $requested = $this->positiveInt($request->query('user_id'));

        if ($requested !== null && $requested !== $viewer->id && ! $this->isAdmin($viewer)) {
            return response()->json('Not Authorized', 401);
        }

        $member = $requested === null || $requested === $viewer->id
            ? $viewer
            : User::query()->find($requested);

        if ($member === null) {
            return response()->json([]);
        }

        return response()->json(
            $this->invoices->listFor($member)
                ->map(fn (ShowRegistration $registration): array => [
                    'id' => (int) $registration->id,
                    'name' => (string) $registration->carShow?->name,
                ])
                ->values()
                ->all(),
        );
    }

    /**
     * GET member/invoice?registration_id: the registration with its names and `totals`. Only the
     * owner or an administrator may read it. Anyone else gets the same 404 as an unknown id, so
     * the response does not reveal that the registration exists.
     */
    public function show(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $registrationId = $this->positiveInt($request->query('registration_id'));

        $registration = $registrationId === null ? null : $this->invoices->find($registrationId);

        if (
            $viewer === null
            || $registration === null
            || ($registration->user_id !== $viewer->id && ! $this->isAdmin($viewer))
        ) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        try {
            $totals = $this->pricing->totalsFor($registration);
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'The registration amounts cannot be priced.'], 422);
        }

        return response()->json((new InvoiceResource($registration, $totals))->resolve($request));
    }

    private function isAdmin(User $user): bool
    {
        return $user->type_id === UserType::Administrator;
    }

    private function positiveInt(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $int === false ? null : $int;
    }
}
